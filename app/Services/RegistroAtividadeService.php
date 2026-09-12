<?php

namespace App\Services;

use App\Enums\SituacaoDocumento;
use App\Enums\TipoRegistro;
use App\Models\Credenciamento;
use App\Models\Projeto;
use App\Models\RegistroAtividade;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grava e consulta a trilha de auditoria (submissões, cancelamentos, exclusões,
 * trocas de e-mail, correções do admin e o que ele mexe num rascunho alheio).
 * Escrever é sempre "fire and forget" a partir dos serviços de negócio; ler é
 * exclusividade do painel do admin.
 */
class RegistroAtividadeService
{
    /**
     * O "autor" das linhas que ninguém escreveu — as que o relógio do portal
     * gera sozinho. A coluna `autor_email` não é nula, e um endereço inventado
     * de gente seria pior do que um rótulo que se lê como rótulo.
     */
    public const AUTOR_SISTEMA = 'sistema@fetecms';

    /** Colunas do CSV exportado, na ordem em que aparecem. */
    private const CABECALHO_CSV = [
        'Data', 'Hora', 'Tipo', 'E-mail do autor', 'Nome do autor', 'Papel',
        'Projeto', 'Categoria', 'Dono da inscrição', 'Detalhes',
    ];

    public function submissao(Projeto $projeto, User $autor): RegistroAtividade
    {
        return $this->registrarProjeto(TipoRegistro::Submissao, $projeto, $autor);
    }

    public function cancelamento(Projeto $projeto, User $autor): RegistroAtividade
    {
        return $this->registrarProjeto(TipoRegistro::Cancelamento, $projeto, $autor);
    }

    public function exclusao(Projeto $projeto, User $autor): RegistroAtividade
    {
        return $this->registrarProjeto(TipoRegistro::Exclusao, $projeto, $autor);
    }

    /**
     * Troca de e-mail. `$anterior` precisa ser lido ANTES de salvar o usuário;
     * `$autor` é quem executou (o próprio dono ou um admin).
     */
    public function trocaEmail(User $dono, string $anterior, string $novo, User $autor): RegistroAtividade
    {
        return RegistroAtividade::create([
            'tipo' => TipoRegistro::TrocaEmail,
            'user_id' => $autor->id,
            // O autor é identificado pelo e-mail que ele tinha ao agir: se trocou
            // o próprio, o registro guarda o antigo — é assim que ele era conhecido.
            'autor_email' => $autor->is($dono) ? $anterior : $autor->email,
            'autor_nome' => $autor->name,
            'autor_role' => $autor->role?->value,
            'dono_email' => $anterior,
            'dono_nome' => $dono->name,
            'detalhes' => ['de' => $anterior, 'para' => $novo],
        ]);
    }

    /**
     * Mudança de um parâmetro da avaliação online (datas e mínimos). Guarda o
     * valor anterior e o novo já formatados para leitura.
     */
    public function parametroAvaliacao(TipoRegistro $tipo, User $admin, ?string $de, ?string $para): RegistroAtividade
    {
        return RegistroAtividade::create([
            'tipo' => $tipo,
            'user_id' => $admin->id,
            'autor_email' => $admin->email,
            'autor_nome' => $admin->name,
            'autor_role' => $admin->role?->value,
            'detalhes' => ['de' => $de, 'para' => $para],
        ]);
    }

    /**
     * Correção de um projeto pelo admin (categoria, área, subárea ou vídeo).
     * A justificativa é obrigatória na tela e vem junto para o registro poder
     * responder "por que isso mudou".
     */
    public function correcaoProjeto(
        TipoRegistro $tipo,
        Projeto $projeto,
        User $admin,
        ?string $de,
        ?string $para,
        string $justificativa,
        ?string $campo = null,
    ): RegistroAtividade {
        // `campo` abre a frase quando um mesmo tipo cobre vários campos — é o
        // caso do coorientador (nome, e-mail, CPF, telefone). Só ele sai quando
        // é nulo: `de` e `para` precisam CONTINUAR na lista mesmo nulos, que é
        // como a trilha mostra "(sem valor)".
        return $this->registrarNoProjeto($tipo, $projeto, $admin, ($campo === null ? [] : ['campo' => $campo]) + [
            'de' => $de,
            'para' => $para,
            'justificativa' => $justificativa,
        ]);
    }

    /**
     * O admin retirando a designação de um projeto do avaliador (Avaliação
     * online → Designações). Guarda de quem saiu e para quem foi — ou que ela
     * ficou sem dono, quando não havia ninguém elegível.
     */
    public function designacaoRetirada(
        Projeto $projeto,
        User $admin,
        string $de,
        ?string $para,
        string $situacao,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::AvaliacaoDesignacaoRetirada, $projeto, $admin, [
            'campo' => $situacao,
            'de' => $de,
            'para' => $para ?? '(sem avaliador)',
        ]);
    }

    /**
     * O prazo estourou: uma avaliação que ficou aberta tempo demais foi
     * devolvida ao bolo pelo próprio sistema.
     *
     * O autor não é ninguém — foi o relógio —, então o registro sai em nome do
     * portal. É intervenção de verdade (tira um projeto das mãos de quem já
     * começou), e por isso entra na trilha; a devolução rotineira de fim de
     * sessão não entra, senão cada logout viraria uma linha.
     *
     * O que o avaliador já preencheu **não é apagado**: ele retoma de onde
     * parou, se o projeto ainda aceitar avaliação.
     */
    public function avaliacaoDevolvidaPorPrazo(
        Projeto $projeto,
        string $avaliador,
        int $dias,
    ): RegistroAtividade {
        $dono = $projeto->relationLoaded('user') ? $projeto->user : $projeto->user()->first();

        return RegistroAtividade::create([
            'tipo' => TipoRegistro::AvaliacaoDevolvidaPorPrazo,
            'user_id' => null,
            'autor_email' => self::AUTOR_SISTEMA,
            'autor_nome' => 'Sistema',
            'autor_role' => null,
            'projeto_id' => $projeto->id,
            'projeto_titulo' => $projeto->titulo,
            'projeto_categoria' => $projeto->categoria?->value,
            'dono_email' => $dono?->email,
            'dono_nome' => $dono?->name,
            'detalhes' => [
                'campo' => 'Avaliação aberta há mais de '.$dias.' dia(s)',
                'de' => $avaliador,
                'para' => '(devolvida para a distribuição)',
            ],
        ]);
    }

    /**
     * O admin mexendo no rascunho de outra pessoa (Projetos em rascunho): uma
     * linha por campo alterado, com o "de → para". Quem fecha a sequência é a
     * submissaoRascunho(), que carrega a justificativa obrigatória.
     */
    public function alteracaoRascunho(
        Projeto $projeto,
        User $admin,
        string $campo,
        ?string $de,
        ?string $para,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::RascunhoAlteracao, $projeto, $admin, [
            'campo' => $campo,
            'de' => $de,
            'para' => $para,
        ]);
    }

    /**
     * Lista final oficial: a publicação e cada projeto acrescentado ou retirado
     * depois. `$projeto` é nulo na oficialização (o registro é da lista toda).
     */
    public function listaFinal(
        TipoRegistro $tipo,
        User $admin,
        string $lista,
        ?Projeto $projeto,
        ?string $justificativa,
        ?string $detalhe = null,
    ): RegistroAtividade {
        $detalhes = array_filter([
            'campo' => $lista,
            'de' => $tipo === TipoRegistro::ListaFinalProjetoRemovido ? ($projeto?->titulo ?? $detalhe) : null,
            'para' => $tipo === TipoRegistro::ListaFinalProjetoRemovido ? null : ($projeto?->titulo ?? $detalhe),
            'justificativa' => $justificativa,
        ], fn ($v) => $v !== null);

        // Na remoção o "para" some de propósito (o projeto saiu), mas a chave
        // precisa existir para o texto sair como "Título → (sem valor)".
        $detalhes['para'] ??= null;

        if ($projeto === null) {
            return RegistroAtividade::create([
                'tipo' => $tipo,
                'user_id' => $admin->id,
                'autor_email' => $admin->email,
                'autor_nome' => $admin->name,
                'autor_role' => $admin->role?->value,
                'detalhes' => $detalhes,
            ]);
        }

        return $this->registrarNoProjeto($tipo, $projeto, $admin, $detalhes);
    }

    /**
     * Credenciamento de um projeto no evento: quem atendeu, quando e o que
     * ficou pendente. O horário fica no próprio registro (`created_at`) e no
     * `credenciamentos.finalizado_em`.
     */
    public function credenciamento(Credenciamento $credenciamento, Projeto $projeto, User $admin): RegistroAtividade
    {
        // Duas pendências diferentes: quem não veio ao balcão e, entre os que
        // vieram, o documento que faltou.
        $faltaram = $credenciamento->pessoas
            ->where('presente', false)
            ->map(fn ($p) => $p->pessoa_nome.': ausente no credenciamento')
            ->values()
            ->all();

        $documentos = $credenciamento->documentos
            ->filter(fn ($d) => $d->situacao === SituacaoDocumento::Ausente)
            ->map(fn ($d) => $d->pessoa_nome.': '.($d->documento?->nome ?? 'documento'))
            ->values()
            ->all();

        $ausentes = array_merge($faltaram, $documentos);

        return $this->registrarNoProjeto(TipoRegistro::CredenciamentoRealizado, $projeto, $admin, array_filter([
            'campo' => 'Credenciamento',
            'para' => $credenciamento->finalizado_em?->format('d/m/Y H:i'),
            'pendencias' => $ausentes === [] ? null : $ausentes,
            'justificativa' => $credenciamento->observacao,
        ], fn ($v) => $v !== null));
    }

    /**
     * O avaliador corrigindo o parecer final de uma avaliação já enviada. Um
     * registro por campo alterado, com o "de → para" e a justificativa; o autor
     * é o próprio avaliador (é ele quem edita).
     */
    public function parecerEditado(
        Projeto $projeto,
        User $avaliador,
        string $campo,
        ?string $de,
        ?string $para,
        string $justificativa,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::AvaliacaoParecerEditado, $projeto, $avaliador, [
            'campo' => $campo,
            'de' => $de,
            'para' => $para,
            'justificativa' => $justificativa,
        ]);
    }

    /**
     * Retirada de kit: quem levou, de quem, e quando.
     *
     * Vale tanto para a retirada feita no próprio credenciamento quanto para a
     * do colega que apareceu depois — é o mesmo fato, e ele precisa ficar
     * registrado das duas vezes, porque o kit sai da mão da organização.
     *
     * @param  list<string>  $pessoas  nomes de quem teve o kit retirado
     */
    public function kitRetirado(
        Projeto $projeto,
        User $admin,
        string $responsavel,
        array $pessoas,
        ?Carbon $quando = null,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::CredenciamentoKitRetirado, $projeto, $admin, [
            'responsavel' => $responsavel,
            'kits' => $pessoas,
            'quando' => ($quando ?? now())->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Um administrador continuou o atendimento que outra pessoa deixou em
     * rascunho. A justificativa só existe quando o dono anterior era um admin
     * permanente — assumir o rascunho de uma conta temporária é rotina de troca
     * de turno.
     */
    public function rascunhoCredenciamentoAssumido(
        Projeto $projeto,
        User $admin,
        string $anterior,
        ?string $justificativa = null,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(
            TipoRegistro::CredenciamentoRascunhoAssumido,
            $projeto,
            $admin,
            array_filter([
                'campo' => 'Rascunho do credenciamento',
                'de' => $anterior,
                'para' => $admin->name,
                'justificativa' => $justificativa,
            ], fn ($v) => $v !== null),
        );
    }

    /**
     * Credenciamento cancelado: o projeto volta para a fila do balcão. Guarda
     * quem tinha credenciado, quando, e a justificativa de quem desfez.
     */
    public function credenciamentoCancelado(
        Credenciamento $credenciamento,
        Projeto $projeto,
        User $admin,
        string $justificativa,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::CredenciamentoCancelado, $projeto, $admin, [
            'campo' => 'Credenciamento',
            'de' => trim(($credenciamento->autor?->name ?? 'desconhecido').' · '
                .($credenciamento->finalizado_em?->format('d/m/Y H:i') ?? '')),
            'para' => '(cancelado)',
            'justificativa' => $justificativa,
        ]);
    }

    /**
     * Almoxarifado: material guardado. Quem deixou e o que entrou — a lista
     * inteira, porque é ela que a retirada vai conferir depois.
     *
     * @param  list<string>  $itens
     */
    public function almoxarifadoGuarda(
        Projeto $projeto,
        User $admin,
        string $responsavel,
        array $itens,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::AlmoxarifadoGuarda, $projeto, $admin, [
            'responsavel' => $responsavel,
            'itens' => $itens,
        ]);
    }

    /**
     * Almoxarifado: material retirado. Quem levou, o quê, quando — e se com
     * isso o registro ficou zerado ou ainda tem volume no balcão.
     *
     * @param  list<string>  $itens
     */
    public function almoxarifadoRetirada(
        Projeto $projeto,
        User $admin,
        string $responsavel,
        array $itens,
        bool $completa,
        ?Carbon $quando = null,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::AlmoxarifadoRetirada, $projeto, $admin, [
            'responsavel' => $responsavel,
            'itens' => $itens,
            'completa' => $completa,
            'quando' => ($quando ?? now())->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Almoxarifado: registro corrigido. Guarda o antes e o depois inteiros —
     * a correção pode mexer em quem deixou e na lista de itens ao mesmo tempo,
     * e é a composição que importa reconstituir.
     *
     * @param  array{responsavel:string, itens:list<string>}  $antes
     * @param  array{responsavel:string, itens:list<string>}  $depois
     */
    public function almoxarifadoEdicao(
        Projeto $projeto,
        User $admin,
        array $antes,
        array $depois,
        string $justificativa,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::AlmoxarifadoEdicao, $projeto, $admin, [
            'de' => $antes['responsavel'].' · '.implode('; ', $antes['itens']),
            'para' => $depois['responsavel'].' · '.implode('; ', $depois['itens']),
            'campo' => 'Registro do almoxarifado',
            'justificativa' => $justificativa,
        ]);
    }

    /**
     * Almoxarifado: registro excluído. Vai gravado **antes** do delete, com o
     * que estava guardado — depois não há mais de onde tirar.
     *
     * @param  list<string>  $itens
     */
    public function almoxarifadoExclusao(
        Projeto $projeto,
        User $admin,
        string $responsavel,
        array $itens,
        string $justificativa,
    ): RegistroAtividade {
        return $this->registrarNoProjeto(TipoRegistro::AlmoxarifadoExclusao, $projeto, $admin, [
            'campo' => 'Registro do almoxarifado',
            'de' => $responsavel.' · '.implode('; ', $itens),
            'para' => '(excluído)',
            'justificativa' => $justificativa,
        ]);
    }

    /** O admin submetendo o rascunho de outra pessoa, com a justificativa do escape. */
    public function submissaoRascunho(Projeto $projeto, User $admin, string $justificativa): RegistroAtividade
    {
        return $this->registrarNoProjeto(TipoRegistro::RascunhoSubmissao, $projeto, $admin, [
            'justificativa' => $justificativa,
        ]);
    }

    /**
     * Linha da trilha presa a um projeto, executada por um admin. O projeto e o
     * dono são desnormalizados para o registro sobreviver ao delete.
     *
     * @param  array<string, mixed>  $detalhes
     */
    private function registrarNoProjeto(
        TipoRegistro $tipo,
        Projeto $projeto,
        User $admin,
        array $detalhes,
    ): RegistroAtividade {
        $dono = $projeto->relationLoaded('user') ? $projeto->user : $projeto->user()->first();

        return RegistroAtividade::create([
            'tipo' => $tipo,
            'user_id' => $admin->id,
            'autor_email' => $admin->email,
            'autor_nome' => $admin->name,
            'autor_role' => $admin->role?->value,
            'projeto_id' => $projeto->id,
            'projeto_titulo' => $projeto->titulo,
            'projeto_categoria' => $projeto->categoria?->value,
            'dono_email' => $dono?->email,
            'dono_nome' => $dono?->name,
            'detalhes' => $detalhes,
        ]);
    }

    private function registrarProjeto(TipoRegistro $tipo, Projeto $projeto, User $autor): RegistroAtividade
    {
        $dono = $projeto->relationLoaded('user') ? $projeto->user : $projeto->user()->first();

        return RegistroAtividade::create([
            'tipo' => $tipo,
            'user_id' => $autor->id,
            'autor_email' => $autor->email,
            'autor_nome' => $autor->name,
            'autor_role' => $autor->role?->value,
            'projeto_id' => $projeto->id,
            'projeto_titulo' => $projeto->titulo,
            'projeto_categoria' => $projeto->categoria?->value,
            'dono_email' => $dono?->email,
            'dono_nome' => $dono?->name,
            'detalhes' => $autor->isAdmin() && ! $autor->is($dono)
                ? ['por_admin' => true]
                : null,
        ]);
    }

    /**
     * Mapa do evento: a lista de turnos foi gerada (ou gerada de novo).
     *
     * Não aponta para projeto nenhum de propósito — a geração é um ato sobre a
     * lista inteira, e amarrá-la a um projeto qualquer daria a entender que só
     * aquele mudou. O resumo guarda o que a geração decidiu: quantos em cada
     * turno, as capacidades e quais regras estavam ligadas.
     *
     * @param  array<string, mixed>  $resumo
     */
    public function turnosGerados(User $admin, array $resumo): RegistroAtividade
    {
        return RegistroAtividade::create([
            'tipo' => TipoRegistro::TurnosGerados,
            'user_id' => $admin->id,
            'autor_email' => $admin->email,
            'autor_nome' => $admin->name,
            'autor_role' => $admin->role?->value,
            'detalhes' => $resumo,
        ]);
    }

    /**
     * Mapa do evento: o admin moveu um projeto de turno à mão.
     *
     * Sem justificativa — é rearranjo de logística, não escape do edital —, mas
     * registrado: no dia do evento é preciso saber por que um projeto está num
     * horário diferente do que a lista gerada dizia.
     */
    public function turnoProjetoMovido(Projeto $projeto, User $admin, string $de, string $para): RegistroAtividade
    {
        return $this->registrarNoProjeto(TipoRegistro::TurnosProjetoMovido, $projeto, $admin, [
            'de' => $de,
            'para' => $para,
        ]);
    }

    /**
     * Mapa do evento: os estandes foram distribuídos (ou redistribuídos).
     *
     * @param  array<string, mixed>  $resumo
     */
    public function estandesGerados(User $admin, array $resumo): RegistroAtividade
    {
        return RegistroAtividade::create([
            'tipo' => TipoRegistro::EstandesGerados,
            'user_id' => $admin->id,
            'autor_email' => $admin->email,
            'autor_nome' => $admin->name,
            'autor_role' => $admin->role?->value,
            'detalhes' => $resumo,
        ]);
    }

    /**
     * Mapa do evento: o admin trocou um projeto de estande.
     *
     * Quando o número de destino já era de outro projeto, os dois trocam de
     * lugar — e cada um vira um registro, para a trilha explicar as duas pontas
     * da troca em vez de só a que foi clicada.
     */
    public function estandeProjetoMovido(Projeto $projeto, User $admin, string $de, string $para): RegistroAtividade
    {
        return $this->registrarNoProjeto(TipoRegistro::EstandeProjetoMovido, $projeto, $admin, [
            'de' => $de,
            'para' => $para,
        ]);
    }

    /**
     * Consulta filtrada do painel. Filtros aceitos: `tipos` (lista), `de`/`ate`
     * (datas, inclusivas) e `busca` (e-mail, nome ou título do projeto).
     *
     * @param  array{tipos?: array<int, string>|null, de?: string|null, ate?: string|null, busca?: string|null}  $filtros
     * @return Builder<RegistroAtividade>
     */
    public function query(array $filtros): Builder
    {
        $busca = trim((string) ($filtros['busca'] ?? ''));

        $daSecao = ! empty($filtros['secao'])
            ? array_map(fn (TipoRegistro $t) => $t->value, TipoRegistro::daSecao($filtros['secao']))
            : null;

        return RegistroAtividade::query()
            ->when($daSecao !== null, fn ($q) => $q->whereIn('tipo', $daSecao))
            ->when(! empty($filtros['tipos']), fn ($q) => $q->whereIn('tipo', $filtros['tipos']))
            ->when(! empty($filtros['de']), fn ($q) => $q->whereDate('created_at', '>=', $filtros['de']))
            ->when(! empty($filtros['ate']), fn ($q) => $q->whereDate('created_at', '<=', $filtros['ate']))
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->where(function ($sub) use ($termo) {
                    foreach (['autor_email', 'autor_nome', 'dono_email', 'dono_nome', 'projeto_titulo'] as $coluna) {
                        $sub->orWhereRaw('LOWER('.$coluna.') LIKE ?', [$termo]);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** @param array<string, mixed> $filtros */
    public function listar(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        return $this->query($filtros)->paginate($porPagina)->withQueryString();
    }

    /**
     * Totais por tipo respeitando os filtros de período/busca (mas não o de
     * tipo, senão os cartões só mostrariam a aba selecionada).
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, int>
     */
    public function totaisPorTipo(array $filtros): array
    {
        $contagem = $this->query(array_merge($filtros, ['tipos' => null]))
            ->reorder()
            ->toBase()
            ->selectRaw('tipo, COUNT(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $totais = [];
        foreach (TipoRegistro::daSecao($filtros['secao'] ?? null) as $tipo) {
            $totais[$tipo->value] = (int) ($contagem[$tipo->value] ?? 0);
        }

        return $totais;
    }

    /**
     * Gera o CSV (UTF-8 com BOM, separador ";" — o que o Excel em pt_BR espera)
     * respeitando os mesmos filtros da tela. Percorre em chunks para não carregar
     * a trilha inteira na memória.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function exportarCsv(array $filtros): string
    {
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}"); // BOM: faz o Excel reconhecer os acentos
        fputcsv($saida, self::CABECALHO_CSV, ';');

        $this->query($filtros)->chunk(500, function ($registros) use ($saida) {
            foreach ($registros as $registro) {
                fputcsv($saida, $this->linhaCsv($registro), ';');
            }
        });

        rewind($saida);
        $csv = stream_get_contents($saida);
        fclose($saida);

        return $csv;
    }

    /** @return array<int, string> */
    private function linhaCsv(RegistroAtividade $registro): array
    {
        return [
            $registro->created_at?->format('d/m/Y') ?? '',
            $registro->created_at?->format('H:i') ?? '',
            $registro->tipo->label(),
            $registro->autor_email,
            $registro->autor_nome ?? '',
            $registro->autor_role ?? '',
            $registro->projeto_titulo ?? '',
            $registro->projeto_categoria ?? '',
            $registro->dono_email ?? '',
            $this->descreverDetalhes($registro),
        ];
    }

    /** Texto legível do contexto do evento (o que vai para a última coluna do CSV). */
    public function descreverDetalhes(RegistroAtividade $registro): string
    {
        $detalhes = $registro->detalhes ?? [];
        $partes = [];

        // O almoxarifado descreve movimento de material, não "de → para".
        if ($registro->tipo === TipoRegistro::AlmoxarifadoGuarda) {
            return ($detalhes['responsavel'] ?? 'alguém').' guardou: '
                .implode('; ', (array) ($detalhes['itens'] ?? []));
        }

        if ($registro->tipo === TipoRegistro::AlmoxarifadoRetirada) {
            return ($detalhes['responsavel'] ?? 'alguém')
                .(($detalhes['completa'] ?? false) ? ' retirou tudo: ' : ' retirou: ')
                .implode('; ', (array) ($detalhes['itens'] ?? []))
                .(empty($detalhes['quando']) ? '' : ' · em '.$detalhes['quando']);
        }

        // A retirada de kit não é um "de → para": é quem levou, de quem e quando.
        if ($registro->tipo === TipoRegistro::CredenciamentoKitRetirado) {
            return ($detalhes['responsavel'] ?? 'alguém')
                .' retirou o kit de: '.implode('; ', (array) ($detalhes['kits'] ?? []))
                .(empty($detalhes['quando']) ? '' : ' · em '.$detalhes['quando']);
        }

        // A geração dos turnos é um ato sobre a lista inteira: o que interessa
        // é o placar dos dois turnos e quais regras estavam ligadas.
        if ($registro->tipo === TipoRegistro::TurnosGerados) {
            $regras = (array) ($detalhes['regras'] ?? []);

            return sprintf(
                'turno A: %d · turno B: %d · regras: %s',
                (int) ($detalhes['turno_a'] ?? 0),
                (int) ($detalhes['turno_b'] ?? 0),
                $regras === [] ? 'nenhuma' : implode('; ', $regras),
            );
        }

        if ($registro->tipo === TipoRegistro::EstandesGerados) {
            $faixas = (array) ($detalhes['faixas'] ?? []);

            return sprintf(
                '%d projeto(s) em estande · faixas: %s',
                (int) ($detalhes['total'] ?? 0),
                $faixas === [] ? 'nenhuma (numeração corrida)' : implode('; ', $faixas),
            );
        }

        if ($registro->tipo === TipoRegistro::TrocaEmail && isset($detalhes['de'], $detalhes['para'])) {
            $partes[] = $detalhes['de'].' → '.$detalhes['para'];
        }
        $comDeEPara = in_array($registro->tipo->secao(), [
            TipoRegistro::SECAO_AVALIACAO, TipoRegistro::SECAO_PROJETOS,
            TipoRegistro::SECAO_RASCUNHOS, TipoRegistro::SECAO_LISTA_FINAL,
            TipoRegistro::SECAO_CREDENCIAMENTO, TipoRegistro::SECAO_MAPA,
        ], true);
        if ($comDeEPara && array_key_exists('para', $detalhes)) {
            $valor = fn ($v) => ($v === null || $v === '') ? '(sem valor)' : (string) $v;
            // No rascunho, um registro por campo: o nome dele abre a frase.
            $prefixo = ! empty($detalhes['campo']) ? $detalhes['campo'].': ' : '';
            $partes[] = $prefixo.$valor($detalhes['de'] ?? null).' → '.$valor($detalhes['para']);
        }
        if (! empty($detalhes['pendencias'])) {
            $partes[] = 'ausentes: '.implode('; ', (array) $detalhes['pendencias']);
        }
        if (! empty($detalhes['justificativa'])) {
            $partes[] = 'justificativa: '.$detalhes['justificativa'];
        }
        if (! empty($detalhes['por_admin'])) {
            $partes[] = 'executado pelo admin';
        }
        if (($detalhes['origem'] ?? null) === 'historico') {
            $partes[] = 'registro histórico (anterior à trilha)';
        }

        return implode(' · ', $partes);
    }
}
