<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Support\JanelaTurnos;
use App\Support\LeitorPlanilha;
use App\Support\PlanilhaXlsx;
use App\Support\RubricaPresencial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial **em lote**, pela planilha (Sprint 239).
 *
 * O avaliador — o do comitê, sobretudo — anda pelos estandes com um caderno e
 * lança tudo depois. Em vez de abrir o wizard projeto a projeto, ele baixa uma
 * planilha com os projetos **designados a ele**, uma linha por projeto e uma
 * coluna por pergunta da rubrica presencial, preenche e devolve.
 *
 * O caminho tem três passos, como o das contas temporárias em lote:
 *
 * 1. **modelo** — Turno, Stand, Código e Projeto preenchidos (os dois últimos
 *    só para leitura), as perguntas na ordem do documento, com o tipo no
 *    título (S/N ou 0-2-10), e o parecer. O que ele já salvou como rascunho
 *    vem preenchido. As condicionais (2.2, 3.2, 4.2, 4.3, 14.2) têm coluna
 *    própria e só se preenchem quando a pergunta de origem é "Sim".
 * 2. **prévia** — nada é gravado. O cabeçalho é conferido primeiro (coluna que
 *    falta, que não é reconhecida ou que aparece duas vezes recusa o arquivo
 *    inteiro, dizendo qual); depois cada linha, com o motivo de cada erro:
 *    valor fora da escala, S/N escrito de outro jeito, condicional preenchida
 *    sem a origem em "Sim", pergunta em branco, estande que não é dele.
 * 3. **envio** — cada linha válida vira uma avaliação pelo mesmo fluxo do
 *    wizard ({@see AvaliacaoPresencialFluxoService}): abre (se preciso), salva
 *    o rascunho e envia. Se o horário não deixa enviar — o comitê fora do
 *    horário de envio —, fica o rascunho, e a mesma planilha pode subir de novo
 *    quando o horário reabrir. Uma linha que falha não derruba as outras.
 */
class LotePresencialService
{
    /** Linhas por planilha: os designados de um avaliador cabem com folga. */
    public const MAX_LINHAS = 100;

    private const ESCALA = [0, 2, 4, 6, 8, 10];

    public function __construct(
        private readonly AvaliacaoPresencialFluxoService $fluxo,
        private readonly DistribuicaoPresencialService $distribuicao,
        private readonly ListaFinalService $listas,
    ) {}

    /** Os bytes do modelo, com os designados dele. */
    public function modelo(User $avaliador, bool $teste = false): string
    {
        $perguntas = self::perguntas();
        $cabecalho = ['Turno', 'Stand', 'Código', 'Projeto', ...array_map(fn (array $p) => self::titulo($p), $perguntas), 'Parecer para a equipe (opcional)'];

        $linhas = array_map(function (array $d) use ($perguntas) {
            $respostas = $d['avaliacao']?->respostas ?? [];

            return [
                $d['turno']?->label(),
                $d['estande'],
                $d['codigo'],
                $d['projeto']->titulo,
                ...array_map(fn (array $p) => self::textoDaResposta($p, $respostas[$p['chave']] ?? null), $perguntas),
                $d['avaliacao']?->comentario,
            ];
        }, $this->designados($avaliador, $teste));

        return PlanilhaXlsx::gerar(
            'Avaliações',
            $cabecalho,
            $linhas,
            [['largura' => 20], ['largura' => 8], ['largura' => 14], ['largura' => 40],
                ...array_fill(0, count($perguntas), ['largura' => 16, 'texto' => true]),
                ['largura' => 50]],
        );
    }

    /**
     * A prévia: o que cada linha vira, ou por que não vira. Nada é gravado.
     *
     * @return array<string, mixed>
     */
    public function previa(User $avaliador, UploadedFile $arquivo, bool $teste = false): array
    {
        $extensao = strtolower($arquivo->getClientOriginalExtension());
        $linhas = LeitorPlanilha::ler($arquivo->getRealPath(), $extensao === 'xlsx' ? 'xlsx' : 'csv');

        if ($linhas === []) {
            throw ValidationException::withMessages(['arquivo' => 'A planilha está vazia.']);
        }

        [$inicio, $mapa] = $this->cabecalho($linhas);
        $designados = $this->designados($avaliador, $teste);
        $resultado = [];
        $vistos = [];
        $emBranco = 0;

        foreach (array_slice($linhas, $inicio + 1, null, true) as $i => $valores) {
            $linha = $this->lerLinha($valores, $mapa, $i + 1, $designados, $vistos);

            if ($linha === null) {
                $emBranco++;

                continue;
            }

            $resultado[] = $linha;
        }

        if (count($resultado) > self::MAX_LINHAS) {
            throw ValidationException::withMessages([
                'arquivo' => 'A planilha tem '.count($resultado).' linhas preenchidas; o limite é '.self::MAX_LINHAS.'.',
            ]);
        }

        return [
            'linhas' => $resultado,
            'resumo' => [
                'validas' => count(array_filter($resultado, fn (array $l) => $l['erros'] === [])),
                'com_erro' => count(array_filter($resultado, fn (array $l) => $l['erros'] !== [])),
                'em_branco' => $emBranco,
            ],
            'envio' => $this->envio($avaliador, $teste),
        ];
    }

    /**
     * Envia as linhas da prévia. Cada uma passa de novo pelas regras — a lista
     * de designados e a rubrica — e depois pelo fluxo do wizard, então vale
     * tudo que vale lá: vaga, turno, liberação, horário de envio.
     *
     * @param  list<array{linha?: int|null, projeto_id: int, respostas?: array<string, mixed>|null, comentario?: string|null}>  $linhas
     * @return array<string, mixed>
     */
    public function enviar(User $avaliador, array $linhas, bool $teste = false): array
    {
        $designados = collect($this->designados($avaliador, $teste))->keyBy(fn (array $d) => $d['projeto']->id);
        $resultado = [];

        foreach ($linhas as $l) {
            $projetoId = (int) $l['projeto_id'];
            $base = ['linha' => $l['linha'] ?? null, 'projeto_id' => $projetoId, 'titulo' => $designados->get($projetoId)['projeto']->titulo ?? null];
            $respostas = RubricaPresencial::normalizar((array) ($l['respostas'] ?? []));
            $comentario = trim((string) ($l['comentario'] ?? '')) ?: null;

            if (! $designados->has($projetoId)) {
                $resultado[] = [...$base, 'situacao' => 'erro', 'mensagem' => 'Este projeto não está mais entre os designados a você.'];

                continue;
            }

            if (($faltam = RubricaPresencial::pendentes($respostas)) !== []) {
                $resultado[] = [...$base, 'situacao' => 'erro', 'mensagem' => 'Faltam respostas: '.implode(', ', $this->numeros($faltam)).'.'];

                continue;
            }

            $resultado[] = [...$base, ...$this->enviarUma($avaliador, $designados->get($projetoId), $respostas, $comentario, $teste)];
        }

        $conta = fn (string $s) => count(array_filter($resultado, fn (array $r) => $r['situacao'] === $s));

        return [
            'linhas' => $resultado,
            'resumo' => ['enviadas' => $conta('enviada'), 'rascunhos' => $conta('rascunho'), 'erros' => $conta('erro')],
        ];
    }

    // --- Internos ----------------------------------------------------------

    /**
     * Uma linha pelo fluxo do wizard: abre, salva o rascunho e, se o horário
     * deixa, envia. O que já foi salvo fica, mesmo quando o envio é recusado.
     *
     * @param  array<string, mixed>  $designado
     * @param  array<string, int|string>  $respostas
     * @return array<string, mixed>
     */
    private function enviarUma(User $avaliador, array $designado, array $respostas, ?string $comentario, bool $teste): array
    {
        $salvo = false;

        try {
            $avaliacao = $designado['avaliacao'];

            if ($avaliacao === null || $avaliacao->status !== StatusAvaliacao::EmAndamento) {
                $avaliacao = AvaliacaoPresencial::findOrFail($this->fluxo->iniciar($avaliador, $designado['projeto'], $teste)['id']);
            }

            $dados = ['respostas' => $respostas, 'comentario' => $comentario];
            $detalhe = $this->fluxo->salvarRascunho($avaliador, $avaliacao, $dados, $teste);
            $salvo = true;

            if (! $detalhe['pode_enviar']) {
                return ['situacao' => 'rascunho', 'mensagem' => $detalhe['envio_fechado'] ?? 'Salva como rascunho.'];
            }

            $enviada = $this->fluxo->concluir($avaliador, $avaliacao->fresh(), $dados, $teste);

            return ['situacao' => 'enviada', 'nota' => $enviada['nota'], 'mensagem' => 'Avaliação enviada.'];
        } catch (ValidationException $e) {
            $motivo = collect($e->errors())->flatten()->first() ?? $e->getMessage();

            return [
                'situacao' => $salvo ? 'rascunho' : 'erro',
                'mensagem' => $salvo ? "Salva como rascunho, mas não enviada: {$motivo}" : $motivo,
            ];
        }
    }

    /**
     * Acha a linha do cabeçalho (a primeira, entre as cinco do topo, que tem a
     * coluna do estande — o modelo pode ganhar um título em cima) e diz que
     * coluna é cada uma. Faltando, sobrando ou repetindo coluna, recusa o
     * arquivo inteiro: com o cabeçalho errado, nenhuma linha se lê direito.
     *
     * @param  list<list<string>>  $linhas
     * @return array{0: int, 1: array<int, string>} o índice da linha e coluna → campo
     */
    private function cabecalho(array $linhas): array
    {
        $inicio = null;

        foreach (array_slice($linhas, 0, 5, true) as $i => $valores) {
            foreach ($valores as $v) {
                if (in_array(self::normalizar($v), ['stand', 'estande'], true)) {
                    $inicio = $i;
                    break 2;
                }
            }
        }

        if ($inicio === null) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não encontrei a coluna "Stand" no cabeçalho. Use a planilha baixada nesta tela, sem apagar a primeira linha.',
            ]);
        }

        $porNumero = [];
        foreach (self::perguntas() as $p) {
            $porNumero[$p['numero']] = $p['chave'];
        }

        $mapa = [];
        $desconhecidas = [];

        foreach ($linhas[$inicio] as $coluna => $titulo) {
            $campo = $this->campoDoTitulo($titulo, $porNumero);

            if ($campo === null) {
                if (trim($titulo) !== '') {
                    $desconhecidas[] = '"'.trim($titulo).'" (coluna '.self::letra($coluna).')';
                }

                continue;
            }

            if (in_array($campo, $mapa, true)) {
                throw ValidationException::withMessages([
                    'arquivo' => 'A coluna "'.trim($titulo).'" (coluna '.self::letra($coluna).') aparece duas vezes. Deixe uma só.',
                ]);
            }

            $mapa[$coluna] = $campo;
        }

        if ($desconhecidas !== []) {
            throw ValidationException::withMessages([
                'arquivo' => 'Coluna não reconhecida: '.implode(', ', $desconhecidas).'. Cada pergunta começa pelo número dela (ex.: "6.1 …"); não renomeie nem acrescente colunas.',
            ]);
        }

        // O estande está garantido: foi por ele que o cabeçalho foi achado.
        $presentes = array_values($mapa);
        $faltam = array_keys(array_filter($porNumero, fn (string $chave) => ! in_array($chave, $presentes, true)));

        if ($faltam !== []) {
            throw ValidationException::withMessages([
                'arquivo' => 'Faltam colunas na planilha: '.implode(', ', $faltam).'. Baixe o modelo de novo: ele traz todas as perguntas da rubrica, inclusive as condicionais.',
            ]);
        }

        return [$inicio, $mapa];
    }

    /** Que campo é a coluna, pelo título: o número da pergunta, ou um dos fixos. */
    private function campoDoTitulo(string $titulo, array $porNumero): ?string
    {
        $limpo = trim($titulo);

        if (preg_match('/^(\d{1,2}\.\d{1,2})(?!\d)/', $limpo, $m)) {
            return $porNumero[$m[1]] ?? null;
        }

        $normal = self::normalizar($limpo);

        return match (true) {
            $normal === 'turno' => 'turno',
            in_array($normal, ['stand', 'estande'], true) => 'estande',
            in_array($normal, ['codigo', 'codigo do projeto'], true) => 'codigo',
            in_array($normal, ['projeto', 'titulo', 'titulo do projeto'], true) => 'titulo',
            str_starts_with($normal, 'parecer') => RubricaPresencial::COMENTARIO,
            default => null,
        };
    }

    /**
     * Uma linha da planilha: o projeto (pelo estande e o turno) e as
     * respostas, com cada erro dito por extenso. Linha sem nenhuma resposta é
     * ignorada (nula) — é o projeto que ele ainda não avaliou.
     *
     * @param  list<string>  $valores
     * @param  array<int, string>  $mapa
     * @param  list<array<string, mixed>>  $designados
     * @param  array<int, int>  $vistos  projeto → linha em que já apareceu
     * @return array<string, mixed>|null
     */
    private function lerLinha(array $valores, array $mapa, int $numero, array $designados, array &$vistos): ?array
    {
        $celula = [];
        foreach ($mapa as $coluna => $campo) {
            $celula[$campo] = trim((string) ($valores[$coluna] ?? ''));
        }

        $perguntas = self::perguntas();
        $preenchidas = array_filter($perguntas, fn (array $p) => ($celula[$p['chave']] ?? '') !== '');

        if ($preenchidas === [] && ($celula[RubricaPresencial::COMENTARIO] ?? '') === '') {
            return null;
        }

        $erros = [];
        $designado = $this->acharProjeto($celula['estande'] ?? '', $celula['turno'] ?? '', $designados, $erros);

        if ($designado !== null && isset($vistos[$designado['projeto']->id])) {
            $erros[] = 'Este projeto já aparece na linha '.$vistos[$designado['projeto']->id].'.';
        } elseif ($designado !== null) {
            $vistos[$designado['projeto']->id] = $numero;
        }

        // Os valores, pergunta a pergunta.
        $respostas = [];
        $invalidas = [];
        foreach ($perguntas as $p) {
            $texto = $celula[$p['chave']] ?? '';

            if ($texto === '') {
                continue;
            }

            $valor = self::valorDaCelula($p, $texto);

            if ($valor === null) {
                $erros[] = self::erroDeValor($p, $texto);
                $invalidas[] = $p['chave'];

                continue;
            }

            $respostas[$p['chave']] = $valor;
        }

        // As condicionais preenchidas sem a origem em "Sim", e o que falta.
        foreach ($perguntas as $p) {
            if (isset($p['condicao']) && array_key_exists($p['chave'], $respostas) && ! RubricaPresencial::aplicavel($p, $respostas)) {
                $origem = self::numeroDe($p['condicao']['pergunta']);
                $erros[] = "{$p['numero']}: só se responde quando a {$origem} é \"S\". Apague o valor ou mude a {$origem}.";
            }
        }

        // O que falta, sem repetir a que já foi dita como valor inválido.
        $faltam = array_values(array_diff(RubricaPresencial::pendentes($respostas), $invalidas));
        if ($faltam !== []) {
            $erros[] = 'Faltam respostas: '.implode(', ', $this->numeros($faltam)).'.';
        }

        $comentario = $celula[RubricaPresencial::COMENTARIO] ?? '';
        if (mb_strlen($comentario) > 2000) {
            $erros[] = 'O parecer passa de 2.000 caracteres.';
        }

        $limpas = RubricaPresencial::normalizar($respostas);

        return [
            'linha' => $numero,
            'projeto_id' => $designado['projeto']->id ?? null,
            'titulo' => $designado['projeto']->titulo ?? ($celula['titulo'] ?? null),
            'codigo' => $designado['codigo'] ?? null,
            'estande' => $designado['estande'] ?? null,
            'turno_label' => ($designado['turno'] ?? null)?->label(),
            'respostas' => (object) $limpas,
            'comentario' => $comentario === '' ? null : $comentario,
            'nota' => $erros === [] ? RubricaPresencial::nota($limpas) : null,
            'erros' => array_values($erros),
        ];
    }

    /**
     * O projeto da linha, pelo estande (e pelo turno, quando o mesmo estande
     * tem um projeto de manhã e outro à tarde).
     *
     * @param  list<array<string, mixed>>  $designados
     * @param  list<string>  $erros
     * @return array<string, mixed>|null
     */
    private function acharProjeto(string $estande, string $turno, array $designados, array &$erros): ?array
    {
        $numero = preg_replace('/\D/', '', $estande);

        if ($numero === '') {
            $erros[] = 'Informe o número do estande na coluna "Stand".';

            return null;
        }

        $numero = (int) $numero;
        $doTurno = $turno === '' ? null : self::turno($turno);

        if ($turno !== '' && $doTurno === null) {
            $erros[] = "Turno \"{$turno}\" não reconhecido — use A (manhã) ou B (tarde).";

            return null;
        }

        $achados = array_values(array_filter(
            $designados,
            fn (array $d) => $d['estande'] === $numero && ($doTurno === null || $d['turno'] === $doTurno),
        ));

        if (count($achados) === 1) {
            return $achados[0];
        }

        $rotulo = 'Estande '.str_pad((string) $numero, 3, '0', STR_PAD_LEFT).($doTurno ? ", {$doTurno->label()}" : '');
        $erros[] = $achados === []
            ? "{$rotulo} não está entre os projetos designados a você (ou já foi enviado)."
            : "{$rotulo} tem projeto nos dois turnos: preencha a coluna \"Turno\".";

        return null;
    }

    /**
     * Os designados como a planilha precisa: projeto, avaliação aberta (se
     * houver), estande, turno e código, na ordem do estande.
     *
     * @return list<array{projeto: Projeto, avaliacao: ?AvaliacaoPresencial, estande: ?int, turno: ?Turno, codigo: ?string}>
     */
    private function designados(User $avaliador, bool $teste): array
    {
        $designados = $this->fluxo->designados($avaliador, $teste);
        $ids = $designados->keys()->all();
        $edicao = Edicao::atual();
        $estandes = EstandeProjeto::where('edicao_id', $edicao?->id)->whereIn('projeto_id', $ids)->get()->keyBy('projeto_id');
        $turnos = $ids === [] ? [] : $this->distribuicao->turnosDe($ids);
        $lista = ListaFinal::vigente($edicao, $teste && (bool) $avaliador->is_demo);
        $codigos = $lista === null ? [] : $this->listas->codigosDaLista($lista);

        return $designados
            ->map(fn (array $d, int $id) => [
                ...$d,
                'estande' => $estandes->get($id)?->numero,
                'turno' => $estandes->get($id)?->turno ?? ($turnos[$id] ?? null),
                'codigo' => $codigos[$id] ?? null,
            ])
            ->sortBy(fn (array $d) => [$d['estande'] ?? PHP_INT_MAX, $d['projeto']->titulo])
            ->values()
            ->all();
    }

    /**
     * Se o envio está aberto agora para ele — o que a prévia avisa antes.
     *
     * @return array{aberto: bool, comite: bool, mensagem: ?string}
     */
    private function envio(User $avaliador, bool $teste): array
    {
        $janela = JanelaTurnos::daEdicao();
        $comite = DistribuicaoPresencialService::daComissao($avaliador);
        $demo = $teste && (bool) $avaliador->is_demo;
        $aberto = $demo || ($comite ? $janela->envioComiteAberto() : $janela->abertas() !== []);
        $proximo = $comite ? $janela->proximoEnvioComite() : null;

        return [
            'aberto' => $aberto,
            'comite' => $comite,
            'mensagem' => $aberto ? null : ($comite
                ? 'Fora do horário de envio: as linhas válidas ficam salvas como rascunho.'
                    .($proximo ? ' O envio abre em '.$proximo->format('d/m \à\s H:i').' — suba a mesma planilha de novo para enviar.' : '')
                : 'Fora do horário de avaliação dos turnos: nenhuma avaliação é enviada agora.'),
        ];
    }

    /**
     * As perguntas da planilha, na ordem do documento: todas as que existem na
     * rubrica, inclusive as condicionais e as sem valor.
     *
     * @return list<array<string, mixed>>
     */
    private static function perguntas(): array
    {
        return RubricaPresencial::perguntas();
    }

    /** "6.1 De que modo… (0-2-10)", com a condição na frente quando houver. */
    private static function titulo(array $p): string
    {
        $tipo = $p['tipo'] === RubricaPresencial::TIPO_ESCALA ? '0-2-10' : (isset($p['nao_se_aplica']) ? 'S/N/NA' : 'S/N');
        $condicao = isset($p['condicao']) ? '[só se '.self::numeroDe($p['condicao']['pergunta']).' = S] ' : '';

        return "{$p['numero']} {$condicao}{$p['texto']} ({$tipo})";
    }

    /** A resposta gravada como a planilha a escreve: S, N, NA ou o número. */
    private static function textoDaResposta(array $p, mixed $valor): string|int|null
    {
        return match (true) {
            $valor === null || $valor === '' => null,
            $p['tipo'] === RubricaPresencial::TIPO_ESCALA => (int) $valor,
            $valor === RubricaPresencial::SIM => 'S',
            $valor === RubricaPresencial::NAO => 'N',
            $valor === RubricaPresencial::NAO_SE_APLICA => 'NA',
            default => null,
        };
    }

    /** O texto da célula como resposta da pergunta, ou nulo se não serve. */
    private static function valorDaCelula(array $p, string $texto): int|string|null
    {
        if ($p['tipo'] === RubricaPresencial::TIPO_ESCALA) {
            $numero = str_replace(',', '.', $texto);

            if (! is_numeric($numero) || (float) $numero !== (float) (int) $numero) {
                return null;
            }

            return in_array((int) $numero, self::ESCALA, true) ? (int) $numero : null;
        }

        $normal = str_replace([' ', '.', '/'], '', self::normalizar($texto));

        return match (true) {
            in_array($normal, ['s', 'sim', 'y', 'yes'], true) => RubricaPresencial::SIM,
            in_array($normal, ['n', 'nao', 'no'], true) => RubricaPresencial::NAO,
            isset($p['nao_se_aplica']) && in_array($normal, ['na', 'nsa', 'naoseaplica', 'naoseaplica(qrcode)', 'qr', 'qrcode'], true) => RubricaPresencial::NAO_SE_APLICA,
            default => null,
        };
    }

    private static function erroDeValor(array $p, string $texto): string
    {
        if ($p['tipo'] === RubricaPresencial::TIPO_ESCALA) {
            return "{$p['numero']}: \"{$texto}\" não está na escala — use 0, 2, 4, 6, 8 ou 10.";
        }

        return "{$p['numero']}: \"{$texto}\" não é resposta válida — use S ou N"
            .(isset($p['nao_se_aplica']) ? ' (ou NA, se não se aplica por QR Code)' : '').'.';
    }

    /** O turno escrito na planilha: "A", "Turno B", "A: manhã", "tarde"… */
    private static function turno(string $texto): ?Turno
    {
        $normal = self::normalizar($texto);

        if (preg_match('/^(turno\s*)?([ab])\b/', $normal, $m)) {
            return Turno::from(strtoupper($m[2]));
        }

        return match (true) {
            str_contains($normal, 'tarde') || str_contains($normal, 'vespert') => Turno::B,
            str_contains($normal, 'manha') || str_contains($normal, 'matut') => Turno::A,
            default => null,
        };
    }

    /**
     * @param  list<string>  $chaves
     * @return list<string>
     */
    private function numeros(array $chaves): array
    {
        return array_map(fn (string $c) => self::numeroDe($c), $chaves);
    }

    private static function numeroDe(string $chave): string
    {
        foreach (RubricaPresencial::perguntas() as $p) {
            if ($p['chave'] === $chave) {
                return $p['numero'];
            }
        }

        return $chave;
    }

    /** Minúsculas, sem acento e com os espaços juntos. */
    private static function normalizar(string $texto): string
    {
        return preg_replace('/\s+/', ' ', strtolower(Str::ascii(trim($texto)))) ?? '';
    }

    /** Índice 0 vira "A" — para dizer em que coluna está o problema. */
    private static function letra(int $indice): string
    {
        $nome = '';

        for ($i = $indice; $i >= 0; $i = intdiv($i, 26) - 1) {
            $nome = chr(65 + ($i % 26)).$nome;
        }

        return $nome;
    }
}
