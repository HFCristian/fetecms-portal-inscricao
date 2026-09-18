<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\DetalheRubrica;
use App\Support\Rubrica;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A nota de uma avaliação **aberta por dentro**: seção por seção da rubrica
 * oficial, cada pergunta com o rótulo que o avaliador leu e os pontos que ela
 * rendeu.
 *
 * Mora aqui, e não no {@see DesignacaoService}, porque duas telas precisam da
 * mesma leitura por caminhos diferentes:
 *
 * - **Designações → Ver notas** abre a avaliação de uma pessoa;
 * - **Verificar disparidade → Ver notas** põe *todas* as avaliações do mesmo
 *   projeto lado a lado, que é o que responde "em que eles discordaram?" — a
 *   nota final diz que a distância existe, a seção diz onde ela nasceu.
 *
 * As duas ficam registradas em Registros → Notas: a consulta não muda nada, mas
 * a nota decide a lista final e o parecer é anônimo para o orientador, então
 * quem lê fica rastreável.
 */
class NotasAvaliacaoService
{
    public function __construct(
        private readonly RegistroAtividadeService $registros,
        private readonly DesignacaoService $designacoes,
    ) {}

    /**
     * As seções pontuadas de uma avaliação, com as perguntas de cada uma.
     *
     * @return list<array<string, mixed>>
     */
    public function secoes(Avaliacao $avaliacao): array
    {
        return DetalheRubrica::secoes($avaliacao->respostas ?? []);
    }

    /**
     * Todas as avaliações **concluídas** de um projeto, uma coluna por
     * avaliador: a nota final de cada um e quanto ele deu em cada seção.
     *
     * É a leitura que a lista de disparidade pede. Ela diz que a maior e a
     * menor nota estão a 4,60 de distância; só a comparação por seção diz se a
     * briga foi na metodologia ou no vídeo — e o nome de quem deu cada nota,
     * que é quem o admin vai procurar depois.
     *
     * **Cada avaliação aberta vira um registro**, como no Ver notas de uma só:
     * abrir a comparação é ler as N notas, e o registro conta acessos. Só a
     * tela devolvida depois de desconsiderar/reconsiderar não registra: aquele
     * ato já tem o registro dele.
     *
     * @return array<string, mixed>
     */
    public function compararProjeto(Projeto $projeto, User $admin, bool $registrar = true): array
    {
        $projeto->loadMissing('area:id,nome');

        $avaliacoes = Avaliacao::where('projeto_id', $projeto->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->with(['avaliador:id,name', 'desconsideradaPor:id,name'])
            ->get()
            // As que contam primeiro, da maior nota para a menor; as
            // desconsideradas descem para o fim, que é onde a tela as separa.
            ->sortBy(fn (Avaliacao $a) => [$a->foiDesconsiderada() ? 1 : 0, -$this->nota($a)])
            ->values();

        $colunas = $avaliacoes->map(function (Avaliacao $a) use ($projeto, $admin, $registrar) {
            $nota = $this->nota($a);

            // A tela devolvida depois de desconsiderar não gera consulta: o
            // registro daquele ato já foi escrito, e repetir "viu a nota" a cada
            // clique encheria a trilha de linhas que ninguém pediu.
            if ($registrar) {
                $this->registros->notasVisualizadas(
                    $projeto,
                    $admin,
                    $a->avaliador?->name ?? 'Avaliador #'.$a->avaliador_id,
                    $nota,
                );
            }

            return [
                'avaliacao_id' => $a->id,
                'avaliador_id' => $a->avaliador_id,
                'avaliador' => $a->avaliador?->name ?? 'Avaliador removido',
                'nota' => $nota,
                // A nota descartada continua aqui, com o motivo: apagá-la
                // destruiria a prova justamente no caso em que alguém contesta.
                'desconsiderada' => $a->foiDesconsiderada(),
                'desconsiderada_em_label' => $a->desconsiderada_em?->format('d/m/Y H:i'),
                'desconsiderada_por' => $a->desconsideradaPor?->name,
                'desconsiderada_motivo' => $a->desconsiderada_motivo,
                'concluida_em' => $a->concluida_em?->toIso8601String(),
                'concluida_em_label' => $a->concluida_em?->format('d/m/Y H:i'),
                // Só os pontos por seção: o detalhe pergunta a pergunta é da
                // tela de uma avaliação só, que é onde ele cabe.
                'secoes' => collect($this->secoes($a))
                    ->mapWithKeys(fn (array $s) => [$s['chave'] => $s['pontos']])
                    ->all(),
                'recomendacao_video' => $a->comentario_video,
                'recomendacao_projeto' => $a->comentario_projeto,
            ];
        })->all();

        // Média e amplitude saem só do que conta: é esse número que está no
        // ranking, e mostrar outro aqui faria a tela discordar da lista final.
        $consideradas = $avaliacoes->reject(fn (Avaliacao $a) => $a->foiDesconsiderada());
        $notas = $consideradas->map(fn (Avaliacao $a) => $this->nota($a));

        return [
            'projeto' => [
                'id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'area' => $projeto->area?->nome,
                'categoria' => $projeto->categoria?->label(),
            ],
            // O cabeçalho das linhas é comum a todos os avaliadores: a rubrica
            // é a mesma, e é isso que torna a comparação legítima.
            'secoes' => array_map(fn (array $s) => [
                'chave' => $s['chave'],
                'titulo' => $s['titulo'],
                'maximo' => round($s['maximo'], 2),
            ], Rubrica::secoesPontuadas()),
            'avaliadores' => $colunas,
            'nota_maxima' => Rubrica::NOTA_MAXIMA,
            'media' => $notas->isEmpty() ? null : round($notas->avg(), 2),
            'amplitude' => $notas->count() < 2 ? null : round($notas->max() - $notas->min(), 2),
            'consideradas' => $consideradas->count(),
            'desconsideradas' => $avaliacoes->count() - $consideradas->count(),
        ];
    }

    /**
     * Tira a nota de um avaliador da classificação — sem apagar a avaliação.
     *
     * O que muda: a média do projeto, o ranking, a lista final, os pareceres do
     * orientador e a **cobertura** (o projeto volta a precisar daquele parecer,
     * abrindo vaga para o substituto). O que não muda: o certificado do
     * avaliador e a posição dele no ranking de quem mais avaliou — quem
     * descartou a nota foi a organização, e o trabalho aconteceu.
     *
     * A justificativa é obrigatória: descartar uma nota pode mudar quem entra na
     * lista final, e escape do edital se explica por escrito.
     */
    public function desconsiderar(
        Avaliacao $avaliacao,
        User $admin,
        string $justificativa,
        ?array $substituicao = null,
    ): array {
        $this->exigirConcluida($avaliacao);

        if ($avaliacao->foiDesconsiderada()) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta nota já está desconsiderada.',
            ]);
        }

        $avaliacao->loadMissing(['projeto', 'avaliador:id,name']);

        DB::transaction(function () use ($avaliacao, $admin, $justificativa) {
            $avaliacao->update([
                'desconsiderada_em' => now(),
                'desconsiderada_por' => $admin->id,
                'desconsiderada_motivo' => $justificativa,
            ]);

            $this->registros->notaDesconsiderada(
                $avaliacao->projeto,
                $admin,
                $avaliacao->avaliador?->name ?? 'Avaliador #'.$avaliacao->avaliador_id,
                $this->nota($avaliacao),
                $justificativa,
            );
        });

        $projeto = $avaliacao->projeto->fresh();
        $dados = $this->compararProjeto($projeto, $admin, registrar: false);
        $dados['substituicao'] = $this->substituir($projeto, $admin, $substituicao);

        return $dados;
    }

    /**
     * O parecer que entra no lugar do que saiu.
     *
     * Desconsiderar abre um buraco na cobertura, e o admin decide na hora como
     * fechá-lo — ou não fechar, que também é resposta quando o projeto ainda tem
     * pareceres de sobra. Dois caminhos:
     *
     * - **outro avaliador**: vira uma designação manual como qualquer outra
     *   (protegida das devoluções automáticas, com o e-mail avisando quem
     *   recebeu). Quem já avaliou o projeto é recusado pelo próprio
     *   {@see DesignacaoService}, inclusive o dono da nota descartada.
     * - **o próprio admin**: no fim do período, com a lista final para fechar,
     *   esperar um terceiro pode não ser opção. Nasce uma avaliação
     *   `pela_organizacao`, **já aberta**, para ele preencher na hora — mesma
     *   rubrica, mesma nota, e fora do ranking e do certificado de avaliador.
     *
     * @param  array<string, mixed>|null  $substituicao
     * @return array<string, mixed>|null
     */
    private function substituir(Projeto $projeto, User $admin, ?array $substituicao): ?array
    {
        $tipo = $substituicao['tipo'] ?? null;

        if ($tipo === null || $tipo === 'nenhuma') {
            return null;
        }

        if ($tipo === 'admin') {
            $minha = $this->avaliacaoDaOrganizacao($projeto, $admin);

            return [
                'tipo' => 'admin',
                // A tela abre o formulário da rubrica com este id.
                'avaliacao_id' => $minha->id,
                'mensagem' => 'Avaliação da organização aberta — preencha a rubrica agora.',
            ];
        }

        $resultado = $this->designacoes->designar(
            [$projeto->id],
            [(int) $substituicao['avaliador_id']],
            $admin,
        );

        return [
            'tipo' => 'avaliador',
            'designadas' => $resultado['designadas'],
            'retomadas' => $resultado['retomadas'],
            'ignoradas' => $resultado['ignoradas'],
            'mensagem' => $resultado['designadas'] + $resultado['retomadas'] > 0
                ? 'Projeto designado para o avaliador escolhido.'
                : 'Ninguém foi designado: '.($resultado['problemas'] ?? 'o avaliador escolhido não pôde receber este projeto.'),
        ];
    }

    /**
     * A avaliação que o admin vai preencher. Se ele já tiver uma neste projeto
     * — porque substituiu antes e não terminou, ou porque a devolução do prazo
     * a escondeu —, é ela que volta: a chave única (projeto, avaliador) não
     * deixa criar outra, e o rascunho dele não se joga fora.
     */
    private function avaliacaoDaOrganizacao(Projeto $projeto, User $admin): Avaliacao
    {
        $existente = Avaliacao::comDevolvidas()
            ->where('projeto_id', $projeto->id)
            ->where('avaliador_id', $admin->id)
            ->first();

        if ($existente !== null) {
            if ($existente->status === StatusAvaliacao::Concluida) {
                throw ValidationException::withMessages([
                    'substituicao' => 'Você já avaliou este projeto — a sua nota anterior está na lista.',
                ]);
            }

            $existente->update([
                'status' => StatusAvaliacao::EmAndamento,
                'devolvida_em' => null,
                'pela_organizacao' => true,
                'designacao_manual' => true,
                'iniciada_em' => $existente->iniciada_em ?? now(),
                'atividade_em' => now(),
            ]);

            return $existente->fresh();
        }

        return Avaliacao::create([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $admin->id,
            // Já nasce aberta: a substituição existe para ser preenchida agora.
            'status' => StatusAvaliacao::EmAndamento,
            'pela_organizacao' => true,
            // Como toda designação manual, ela não é devolvida por rotina
            // automática nenhuma.
            'designacao_manual' => true,
            'iniciada_em' => now(),
            'atividade_em' => now(),
        ]);
    }

    /**
     * Volta atrás: a nota conta de novo.
     *
     * Também pede justificativa — entre desconsiderar e reconsiderar a
     * classificação mudou duas vezes, e a trilha precisa contar as duas.
     */
    public function reconsiderar(Avaliacao $avaliacao, User $admin, string $justificativa): array
    {
        $this->exigirConcluida($avaliacao);

        if (! $avaliacao->foiDesconsiderada()) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta nota já está sendo considerada.',
            ]);
        }

        $avaliacao->loadMissing(['projeto', 'avaliador:id,name']);

        DB::transaction(function () use ($avaliacao, $admin, $justificativa) {
            $avaliacao->update([
                'desconsiderada_em' => null,
                'desconsiderada_por' => null,
                'desconsiderada_motivo' => null,
            ]);

            $this->registros->notaReconsiderada(
                $avaliacao->projeto,
                $admin,
                $avaliacao->avaliador?->name ?? 'Avaliador #'.$avaliacao->avaliador_id,
                $this->nota($avaliacao),
                $justificativa,
            );
        });

        return $this->compararProjeto($avaliacao->projeto->fresh(), $admin, registrar: false);
    }

    /** Sem conclusão não há nota, e sem nota não há o que desconsiderar. */
    private function exigirConcluida(Avaliacao $avaliacao): void
    {
        if ($avaliacao->status !== StatusAvaliacao::Concluida) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta avaliação ainda não foi enviada — só há nota depois que o avaliador conclui.',
            ]);
        }
    }

    /**
     * A nota que vale: a gravada na conclusão. Só quando ela falta é que a
     * recalculada entra — e aí a divergência é problema de outra ordem.
     */
    public function nota(Avaliacao $avaliacao): float
    {
        return round((float) ($avaliacao->nota ?? $avaliacao->notaCalculada()), 2);
    }
}
