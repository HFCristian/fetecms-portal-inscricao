<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Avaliacao;
use App\Models\AvaliadorAreaExtra;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use App\Support\LimitesAvaliacao;
use App\Support\RegrasDistribuicao;
use App\Support\Rubrica;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Telas de "Avaliação online" do admin (E7): panorama dos avaliadores e dos
 * projetos submetidos, designação manual, rankings e a configuração do
 * algoritmo de distribuição (as {@see RegrasDistribuicao} por categoria).
 */
class AdminAvaliacaoService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /** Como a tabela de avaliadores pode ser ordenada (coluna => expressão SQL). */
    private const ORDENACOES_AVALIADOR = [
        'nome' => 'users.name',
        'area' => 'areas.nome',
        'em_avaliacao' => 'em_avaliacao_count',
        'avaliou' => 'avaliou_count',
        'faltam' => 'avaliou_count', // menos avaliadas = mais faltantes: direção invertida
        'criado_em' => 'users.created_at',
    ];

    /**
     * Tabela de avaliadores do admin: uma lista só, com busca por nome/e-mail,
     * filtro por área e ordenação por qualquer coluna.
     *
     * Filtros: `q`, `area_id`, `ordenar` (chave de ORDENACOES_AVALIADOR),
     * `direcao` (asc|desc).
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, User>
     */
    public function avaliadores(array $filtros = [], int $porPagina = 50): LengthAwarePaginator
    {
        return $this->queryAvaliadores($filtros)->paginate($porPagina)->withQueryString();
    }

    /**
     * Uma linha da tabela de avaliadores.
     *
     * @return array<string, mixed>
     */
    public function linhaAvaliador(User $u, ?int $minPorAvaliador = null): array
    {
        $min = $minPorAvaliador ?? Edicao::minPorAvaliador();
        $perfil = $u->avaliadorProfile;
        $avaliou = (int) $u->avaliou_count;

        return [
            'id' => $u->id,
            'nome' => $u->name,
            'email' => $u->email,
            'area_id' => $perfil?->area_id,
            'area' => $perfil?->area?->nome,
            'subarea' => $perfil?->subarea?->nome,
            'em_avaliacao' => (int) $u->em_avaliacao_count,
            'avaliou' => $avaliou,
            'faltam' => max(0, $min - $avaliou),
            'limite' => $perfil?->limite_avaliacoes,
            'is_demo' => (bool) $u->is_demo,
            'comissao_especial' => (bool) $perfil?->comissao_especial,
            'areas_extras' => $perfil?->areasExtras->map(fn ($extra) => [
                'id' => $extra->id,
                'area_id' => $extra->area_id,
                'area' => $extra->area?->nome,
                'subarea_id' => $extra->subarea_id,
                'subarea' => $extra->subarea?->nome,
            ])->values()->all() ?? [],
            'criado_em' => $u->created_at?->toIso8601String(),
            'criado_em_label' => $u->created_at?->format('d/m/Y'),
        ];
    }

    /**
     * Lista enxuta de avaliadores (id, nome, área) para os seletores de
     * designação — sem paginação, em ordem alfabética.
     *
     * @return list<array{id:int, nome:string, area:?string}>
     */
    public function opcoesAvaliadores(bool $somenteComissao = false): array
    {
        return User::query()
            ->where('role', Role::Avaliador->value)
            ->when($somenteComissao, fn ($q) => $q->whereHas(
                'avaliadorProfile',
                fn ($perfil) => $perfil->where('comissao_especial', true),
            ))
            ->with('avaliadorProfile.area:id,nome')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nome' => $u->name,
                'area' => $u->avaliadorProfile?->area?->nome,
            ])
            ->all();
    }

    /** Áreas que têm ao menos um avaliador — as opções do filtro da tabela. */
    public function areasComAvaliador(): array
    {
        return AvaliadorProfile::query()
            ->join('areas', 'areas.id', '=', 'avaliador_profiles.area_id')
            ->select('areas.id', 'areas.nome')
            ->distinct()
            ->orderBy('areas.nome')
            ->get()
            ->map(fn ($linha) => ['id' => (int) $linha->id, 'nome' => $linha->nome])
            ->all();
    }

    /**
     * CSV da tabela de avaliadores (UTF-8 com BOM, separador ";"), no mesmo
     * recorte de filtros que está na tela.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function exportarAvaliadoresCsv(array $filtros = []): string
    {
        $min = Edicao::minPorAvaliador();
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}");
        fputcsv($saida, [
            'Nome', 'E-mail', 'Área', 'Subárea', 'Áreas extras', 'Em avaliação', 'Avaliadas',
            'Faltantes', 'Limite', 'Demo', 'Comissão especial', 'Cadastro',
        ], ';');

        $this->queryAvaliadores($filtros)->chunk(300, function ($avaliadores) use ($saida, $min) {
            foreach ($avaliadores as $u) {
                $linha = $this->linhaAvaliador($u, $min);
                fputcsv($saida, [
                    $linha['nome'],
                    $linha['email'],
                    $linha['area'] ?? '',
                    $linha['subarea'] ?? '',
                    implode(' | ', array_map(
                        fn ($extra) => $extra['area'].($extra['subarea'] ? ' / '.$extra['subarea'] : ''),
                        $linha['areas_extras'],
                    )),
                    $linha['em_avaliacao'],
                    $linha['avaliou'],
                    $linha['faltam'],
                    $linha['limite'] ?? '',
                    $linha['is_demo'] ? 'sim' : 'não',
                    $linha['comissao_especial'] ? 'sim' : 'não',
                    $linha['criado_em_label'] ?? '',
                ], ';');
            }
        });

        rewind($saida);
        $csv = stream_get_contents($saida);
        fclose($saida);

        return $csv;
    }

    /**
     * Base da tabela: avaliadores com o progresso de cada um, já filtrados e
     * ordenados. Serve tanto à listagem paginada quanto ao CSV.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<User>
     */
    private function queryAvaliadores(array $filtros): Builder
    {
        $ordenar = $filtros['ordenar'] ?? 'nome';
        $ordenar = isset(self::ORDENACOES_AVALIADOR[$ordenar]) ? $ordenar : 'nome';
        $direcao = ($filtros['direcao'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        // "Faltam" é o espelho de "avaliou": ordenar por um é ordenar pelo outro ao contrário.
        $direcaoSql = $ordenar === 'faltam' ? ($direcao === 'asc' ? 'desc' : 'asc') : $direcao;
        $busca = trim((string) ($filtros['q'] ?? ''));

        return User::query()
            ->where('users.role', Role::Avaliador->value)
            ->leftJoin('avaliador_profiles', 'avaliador_profiles.user_id', '=', 'users.id')
            ->leftJoin('areas', 'areas.id', '=', 'avaliador_profiles.area_id')
            ->select('users.*')
            ->with([
                'avaliadorProfile.area:id,nome',
                'avaliadorProfile.subarea:id,nome',
                'avaliadorProfile.areasExtras.area:id,nome',
                'avaliadorProfile.areasExtras.subarea:id,nome',
            ])
            ->withCount([
                'avaliacoes as em_avaliacao_count' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
                'avaliacoes as avaliou_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
            ])
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->where(function ($sub) use ($termo) {
                    $sub->whereRaw('LOWER(users.name) LIKE ?', [$termo])
                        ->orWhereRaw('LOWER(users.email) LIKE ?', [$termo]);
                });
            })
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where(function ($sub) use ($areaId) {
                // A área do cadastro OU uma das áreas extras liberadas pelo admin.
                $sub->where('avaliador_profiles.area_id', $areaId)
                    ->orWhereExists(fn ($ex) => $ex->from('avaliador_areas_extras')
                        ->whereColumn('avaliador_areas_extras.avaliador_profile_id', 'avaliador_profiles.id')
                        ->where('avaliador_areas_extras.area_id', $areaId));
            }))
            ->when(($filtros['situacao'] ?? null) === 'comissao', fn ($q) => $q->where('avaliador_profiles.comissao_especial', true))
            ->when(($filtros['situacao'] ?? null) === 'demo', fn ($q) => $q->where('users.is_demo', true))
            ->when(($filtros['situacao'] ?? null) === 'bloqueados', fn ($q) => $q->whereNotNull('avaliador_profiles.limite_avaliacoes'))
            ->orderBy(self::ORDENACOES_AVALIADOR[$ordenar], $direcaoSql)
            ->orderBy('users.name');
    }

    /**
     * Avaliadores agrupados por área, com o progresso de cada um:
     * em_avaliacao (em andamento agora, 0 ou 1), avaliou (concluídas) e
     * faltam (mínimo por avaliador − avaliou, mínimo 0).
     *
     * @return array<int, array{area_id:int, area:string, avaliadores:array}>
     */
    public function avaliadoresPorArea(): array
    {
        $minPorAvaliador = Edicao::minPorAvaliador();

        $avaliadores = User::query()
            ->where('role', Role::Avaliador->value)
            ->with('avaliadorProfile.area:id,nome')
            ->withCount([
                'avaliacoes as em_avaliacao_count' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
                'avaliacoes as avaliou_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
            ])
            ->orderBy('name')
            ->get();

        $grupos = [];
        foreach ($avaliadores as $u) {
            $area = $u->avaliadorProfile?->area;
            $chave = $area?->id ?? 0;
            $grupos[$chave] ??= ['area_id' => (int) ($area?->id ?? 0), 'area' => $area?->nome ?? 'Sem área', 'avaliadores' => []];

            $avaliou = (int) $u->avaliou_count;
            $grupos[$chave]['avaliadores'][] = [
                'id' => $u->id,
                'nome' => $u->name,
                'em_avaliacao' => (int) $u->em_avaliacao_count,
                'avaliou' => $avaliou,
                'faltam' => max(0, $minPorAvaliador - $avaliou),
                'limite' => $u->avaliadorProfile?->limite_avaliacoes,
                'is_demo' => (bool) $u->is_demo,
            ];
        }

        return $this->ordenarPorArea($grupos);
    }

    /**
     * Ranking de avaliadores: quem mais concluiu avaliações. Traz nome, área,
     * quantas concluiu, quantas estão em avaliação agora e de onde a pessoa é.
     *
     * Só entra quem já concluiu ao menos uma — uma lista de zeros não classifica
     * ninguém. Empate divide a posição (dois em 1º, ninguém em 2º), como no
     * perfil do avaliador. Avaliador demo fica de fora.
     *
     * @return list<array<string, mixed>>
     */
    public function rankingAvaliadores(int $limite = 100): array
    {
        $avaliadores = User::query()
            ->where('role', Role::Avaliador->value)
            ->where('is_demo', false)
            ->with([
                'avaliadorProfile.area:id,nome',
                'avaliadorProfile.estado:id,nome,uf',
                'avaliadorProfile.cidade:id,nome',
            ])
            ->withCount([
                'avaliacoes as concluidas_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
                'avaliacoes as em_avaliacao_count' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
            ])
            ->get(['id', 'name'])
            ->filter(fn (User $u) => $u->concluidas_count > 0)
            ->sortBy([
                fn (User $a, User $b) => $b->concluidas_count <=> $a->concluidas_count,
                fn (User $a, User $b) => $b->em_avaliacao_count <=> $a->em_avaliacao_count,
                fn (User $a, User $b) => strcmp($a->name, $b->name),
            ])
            ->take($limite)
            ->values();

        $posicao = 0;
        $anterior = null;

        return $avaliadores->map(function (User $u, int $indice) use (&$posicao, &$anterior) {
            $concluidas = (int) $u->concluidas_count;

            // Mesmo número de avaliações = mesma posição; a próxima diferente
            // pula para o índice real (dois em 1º, o seguinte em 3º).
            if ($concluidas !== $anterior) {
                $posicao = $indice + 1;
                $anterior = $concluidas;
            }

            $perfil = $u->avaliadorProfile;

            return [
                'posicao' => $posicao,
                'avaliador_id' => $u->id,
                'nome' => $u->name,
                'area' => $perfil?->area?->nome,
                'concluidas' => $concluidas,
                'em_avaliacao' => (int) $u->em_avaliacao_count,
                'estado' => $perfil?->estado?->uf,
                'estado_nome' => $perfil?->estado?->nome,
                'cidade' => $perfil?->cidade?->nome,
            ];
        })->all();
    }

    /** Como a tabela de projetos pode ser ordenada (coluna => expressão SQL). */
    private const ORDENACOES_PROJETO = [
        'titulo' => 'projetos.titulo',
        'area' => 'areas.nome',
        'categoria' => 'projetos.categoria',
        'em_avaliacao' => 'em_avaliacao_count',
        'realizadas' => 'realizadas_count',
        'faltantes' => 'realizadas_count', // espelho de realizadas: direção invertida
    ];

    /**
     * Tabela de projetos submetidos do admin: uma lista só, com busca por
     * título, filtro por área e categoria e ordenação por qualquer coluna.
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, Projeto>
     */
    public function projetos(array $filtros = [], int $porPagina = 50): LengthAwarePaginator
    {
        return $this->queryProjetos($filtros)->paginate($porPagina)->withQueryString();
    }

    /**
     * Uma linha da tabela de projetos.
     *
     * @return array<string, mixed>
     */
    public function linhaProjeto(Projeto $p, ?LimitesAvaliacao $limites = null): array
    {
        // O alvo é o da categoria do projeto: FETEC Jr e FUNDECT podem pedir
        // números diferentes de avaliações.
        $min = ($limites ?? Edicao::limites())->minPorProjeto($p->categoria);
        $realizadas = (int) $p->realizadas_count;

        return [
            'id' => $p->id,
            'titulo' => $p->titulo,
            'area_id' => $p->area_id,
            'area' => $p->area?->nome,
            'subarea_id' => $p->subarea_id,
            'subarea' => $p->subarea?->nome,
            'categoria' => $p->categoria?->value,
            'categoria_label' => $p->categoria?->label(),
            // O diálogo de correção abre já preenchido, sem uma segunda consulta.
            'link_video' => $p->link_video,
            'realizadas' => $realizadas,
            'em_avaliacao' => (int) $p->em_avaliacao_count,
            'faltantes' => max(0, $min - $realizadas),
        ];
    }

    /**
     * O mesmo resumo, somado: quantos projetos do recorte inteiro estão com 0,
     * 1, 2 e 3+ avaliações concluídas, sem separar por área. É o card
     * destacado do topo da tela.
     *
     * @param  list<array<string, mixed>>  $porArea  saída de resumoProjetosPorArea()
     * @return array<string, mixed>
     */
    public function resumoProjetosGeral(array $porArea): array
    {
        $geral = ['zero' => 0, 'uma' => 0, 'duas' => 0, 'tres_ou_mais' => 0, 'total' => 0, 'completos' => 0];

        foreach ($porArea as $area) {
            foreach (array_keys($geral) as $chave) {
                $geral[$chave] += (int) ($area[$chave] ?? 0);
            }
        }

        return $geral;
    }

    /**
     * Os campos que a correção manual do admin devolve — o suficiente para a
     * linha da tabela se atualizar sem recarregar a página.
     *
     * @return array<string, mixed>
     */
    public function projetoParaEdicao(Projeto $p): array
    {
        return [
            'id' => $p->id,
            'titulo' => $p->titulo,
            'categoria' => $p->categoria?->value,
            'categoria_label' => $p->categoria?->label(),
            'area_id' => $p->area_id,
            'area' => $p->area?->nome,
            'subarea_id' => $p->subarea_id,
            'subarea' => $p->subarea?->nome,
            'link_video' => $p->link_video,
        ];
    }

    /**
     * Resumo dos projetos por área do conhecimento: quantos estão com 0, 1, 2 e
     * 3 ou mais avaliações concluídas. Responde aos MESMOS filtros da tabela —
     * os cards e a lista mostram sempre o mesmo recorte.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    public function resumoProjetosPorArea(array $filtros = []): array
    {
        $limites = Edicao::limites();

        $grupos = [];
        $this->queryProjetos($filtros)
            ->reorder()
            ->get()
            ->each(function (Projeto $p) use (&$grupos, $limites) {
                $chave = $p->area_id ?? 0;
                $grupos[$chave] ??= [
                    'area_id' => $p->area_id,
                    'area' => $p->area?->nome ?? 'Sem área',
                    'zero' => 0, 'uma' => 0, 'duas' => 0, 'tres_ou_mais' => 0,
                    'total' => 0, 'completos' => 0,
                ];

                $realizadas = (int) $p->realizadas_count;
                $faixa = match (true) {
                    $realizadas === 0 => 'zero',
                    $realizadas === 1 => 'uma',
                    $realizadas === 2 => 'duas',
                    default => 'tres_ou_mais',
                };

                $grupos[$chave][$faixa]++;
                $grupos[$chave]['total']++;
                // "Completo" é em relação ao mínimo da categoria do projeto.
                $grupos[$chave]['completos'] += $realizadas >= $limites->minPorProjeto($p->categoria) ? 1 : 0;
            });

        $lista = array_values($grupos);
        usort($lista, fn ($x, $y) => ($x['area_id'] === null ? 1 : 0) <=> ($y['area_id'] === null ? 1 : 0)
            ?: strcmp($x['area'], $y['area']));

        return $lista;
    }

    /** Áreas que têm ao menos um projeto submetido — as opções do filtro. */
    public function areasComProjeto(): array
    {
        return Projeto::query()
            ->where('projetos.status', ProjetoStatus::Submetido->value)
            ->join('areas', 'areas.id', '=', 'projetos.area_id')
            ->select('areas.id', 'areas.nome')
            ->distinct()
            ->orderBy('areas.nome')
            ->get()
            ->map(fn ($linha) => ['id' => (int) $linha->id, 'nome' => $linha->nome])
            ->all();
    }

    /**
     * CSV da tabela de projetos (UTF-8 com BOM, ";"), no mesmo recorte da tela.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function exportarProjetosCsv(array $filtros = []): string
    {
        $limites = Edicao::limites();
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}");
        fputcsv($saida, ['Título', 'Área', 'Subárea', 'Categoria', 'Em avaliação', 'Realizadas', 'Faltantes'], ';');

        $this->queryProjetos($filtros)->chunk(300, function ($projetos) use ($saida, $limites) {
            foreach ($projetos as $p) {
                $linha = $this->linhaProjeto($p, $limites);
                fputcsv($saida, [
                    $linha['titulo'],
                    $linha['area'] ?? '',
                    $linha['subarea'] ?? '',
                    $linha['categoria_label'] ?? '',
                    $linha['em_avaliacao'],
                    $linha['realizadas'],
                    $linha['faltantes'],
                ], ';');
            }
        });

        rewind($saida);
        $csv = stream_get_contents($saida);
        fclose($saida);

        return $csv;
    }

    /**
     * Base da tabela de projetos, já filtrada e ordenada. Serve à listagem, ao
     * CSV e aos cards de resumo.
     *
     * @param  array<string, mixed>  $filtros
     * @return Builder<Projeto>
     */
    private function queryProjetos(array $filtros): Builder
    {
        $ordenar = $filtros['ordenar'] ?? 'titulo';
        $ordenar = isset(self::ORDENACOES_PROJETO[$ordenar]) ? $ordenar : 'titulo';
        $direcao = ($filtros['direcao'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $direcaoSql = $ordenar === 'faltantes' ? ($direcao === 'asc' ? 'desc' : 'asc') : $direcao;
        $busca = trim((string) ($filtros['q'] ?? ''));

        return Projeto::query()
            ->where('projetos.status', ProjetoStatus::Submetido->value)
            ->leftJoin('areas', 'areas.id', '=', 'projetos.area_id')
            ->select('projetos.*')
            ->with(['area:id,nome', 'subarea:id,nome'])
            ->withCount([
                'avaliacoes as realizadas_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
                'avaliacoes as em_avaliacao_count' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
            ])
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($busca)).'%';
                $q->whereRaw('LOWER(projetos.titulo) LIKE ?', [$termo]);
            })
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('projetos.area_id', $areaId))
            ->when($filtros['categoria'] ?? null, fn ($q, $categoria) => $q->where('projetos.categoria', $categoria))
            ->orderBy(self::ORDENACOES_PROJETO[$ordenar], $direcaoSql)
            ->orderBy('projetos.titulo');
    }

    /**
     * Projetos submetidos agrupados por área, com quantas avaliações (concluídas)
     * cada um já recebeu.
     *
     * @return array<int, array{area_id:int, area:string, projetos:array}>
     */
    public function projetosSubmetidosPorArea(): array
    {
        $limites = Edicao::limites();

        $projetos = Projeto::query()
            ->where('status', ProjetoStatus::Submetido->value)
            ->with('area:id,nome')
            ->withCount([
                'avaliacoes as realizadas' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
                'avaliacoes as em_avaliacao' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
            ])
            ->orderBy('titulo')
            ->get();

        $grupos = [];
        foreach ($projetos as $p) {
            $area = $p->area;
            $chave = $area?->id ?? 0;
            $grupos[$chave] ??= ['area_id' => (int) ($area?->id ?? 0), 'area' => $area?->nome ?? 'Sem área', 'projetos' => []];

            $realizadas = (int) $p->realizadas;
            $grupos[$chave]['projetos'][] = [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'realizadas' => $realizadas,
                'em_avaliacao' => (int) $p->em_avaliacao,
                // Cada projeto precisa do mínimo da categoria dele.
                'faltantes' => max(0, $limites->minPorProjeto($p->categoria) - $realizadas),
            ];
        }

        return $this->ordenarPorArea($grupos);
    }

    /**
     * Projetos que receberam sugestão de reclassificação: pelo menos um avaliador
     * marcou a área e/ou a subárea como incorreta ao concluir. Cada projeto vem
     * com as sugestões individuais e o consenso (opção mais votada).
     *
     * Filtros (todos opcionais): `area_id` (área ATUAL do projeto), `q` (trecho do
     * título) e `de`/`ate` (período da data de avaliação, inclusive).
     *
     * @param  array{area_id?:int|null, q?:string|null, de?:string|null, ate?:string|null}  $filtros
     * @return list<array<string, mixed>>
     */
    public function reclassificacoesSugeridas(array $filtros = []): array
    {
        $avaliacoes = Avaliacao::query()
            ->where('status', StatusAvaliacao::Concluida->value)
            ->where(fn ($q) => $q->where('area_correta', false)->orWhere('subarea_correta', false))
            ->with([
                'avaliador:id,name',
                'areaSugerida:id,nome',
                'subareaSugerida:id,nome',
                'projeto:id,titulo,area_id,subarea_id',
                'projeto.area:id,nome',
                'projeto.subarea:id,nome',
            ])
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->whereHas(
                'projeto', fn ($p) => $p->where('area_id', $areaId)
            ))
            ->when($filtros['q'] ?? null, fn ($q, $termo) => $q->whereHas(
                'projeto', fn ($p) => $p->where('titulo', 'like', '%'.$termo.'%')
            ))
            ->when($filtros['de'] ?? null, fn ($q, $de) => $q->whereDate('concluida_em', '>=', $de))
            ->when($filtros['ate'] ?? null, fn ($q, $ate) => $q->whereDate('concluida_em', '<=', $ate))
            ->orderByDesc('concluida_em')
            ->get()
            // Uma avaliação sem projeto (exclusão em cascata em andamento) não tem o que exibir.
            ->filter(fn (Avaliacao $a) => $a->projeto !== null);

        $projetos = [];
        foreach ($avaliacoes as $a) {
            $projeto = $a->projeto;

            // Sugestão que aponta para a classificação ATUAL já foi aplicada pelo
            // admin — deixa de ser uma troca pendente e some da lista.
            $areaId = $a->area_correta === false && $a->area_sugerida_id
                && $a->area_sugerida_id !== $projeto->area_id ? $a->area_sugerida_id : null;
            $subareaId = $a->subarea_correta === false && $a->subarea_sugerida_id
                && $a->subarea_sugerida_id !== $projeto->subarea_id ? $a->subarea_sugerida_id : null;

            if ($areaId === null && $subareaId === null) {
                continue;
            }

            $id = $projeto->id;
            $projetos[$id] ??= [
                'projeto_id' => $id,
                'titulo' => $projeto->titulo,
                'area_id' => $projeto->area_id,
                'area' => $projeto->area?->nome,
                'subarea_id' => $projeto->subarea_id,
                'subarea' => $projeto->subarea?->nome,
                'sugestoes' => [],
            ];

            $projetos[$id]['sugestoes'][] = [
                'avaliacao_id' => $a->id,
                'avaliador' => $a->avaliador?->name,
                'avaliada_em' => $a->concluida_em?->format('d/m/Y H:i'),
                'avaliada_em_iso' => $a->concluida_em?->toIso8601String(),
                'area_sugerida_id' => $areaId,
                'area_sugerida' => $areaId ? $a->areaSugerida?->nome : null,
                'subarea_sugerida_id' => $subareaId,
                'subarea_sugerida' => $subareaId ? $a->subareaSugerida?->nome : null,
            ];
        }

        $lista = array_map(function (array $p) {
            $p['total_sugestoes'] = count($p['sugestoes']);
            // Opções distintas com a contagem de votos — o admin escolhe qual aceitar.
            $p['opcoes_area'] = $this->opcoes($p['sugestoes'], 'area_sugerida');
            $p['opcoes_subarea'] = $this->opcoes($p['sugestoes'], 'subarea_sugerida');
            $p['area_mais_sugerida'] = $p['opcoes_area'][0] ?? null;
            $p['subarea_mais_sugerida'] = $p['opcoes_subarea'][0] ?? null;

            return $p;
        }, array_values($projetos));

        // Mais sugestões primeiro: é onde o admin deve olhar antes.
        usort($lista, fn ($a, $b) => [$b['total_sugestoes'], $a['titulo']] <=> [$a['total_sugestoes'], $b['titulo']]);

        return $lista;
    }

    /**
     * Opções distintas sugeridas para um campo, com os votos de cada uma e a mais
     * votada primeiro (empate desfeito pelo nome, para a ordem ser estável).
     *
     * @param  list<array<string, mixed>>  $sugestoes
     * @return list<array{id:int, nome:string, votos:int}>
     */
    private function opcoes(array $sugestoes, string $campo): array
    {
        $contagem = [];

        foreach ($sugestoes as $s) {
            $id = $s[$campo.'_id'] ?? null;

            if ($id === null) {
                continue;
            }

            $contagem[$id] ??= ['id' => (int) $id, 'nome' => (string) $s[$campo], 'votos' => 0];
            $contagem[$id]['votos']++;
        }

        $lista = array_values($contagem);
        usort($lista, fn ($a, $b) => [$b['votos'], $a['nome']] <=> [$a['votos'], $b['nome']]);

        return $lista;
    }

    /**
     * Aplica sugestões de reclassificação em lote: troca a área e/ou a subárea dos
     * projetos indicados. Só aceita valores que algum avaliador realmente sugeriu
     * para aquele projeto — este endpoint é "aceitar sugestão", não edição livre.
     *
     * Tudo numa transação: ou o lote inteiro vale, ou nada muda.
     *
     * @param  list<array{projeto_id:int, area_id?:int|null, subarea_id?:int|null}>  $itens
     * @return list<array<string, mixed>> o que mudou em cada projeto
     */
    public function aplicarReclassificacoes(array $itens): array
    {
        return DB::transaction(function () use ($itens) {
            $aplicados = [];

            foreach ($itens as $item) {
                $projeto = Projeto::with(['area:id,nome', 'subarea:id,nome'])->find($item['projeto_id']);

                if (! $projeto) {
                    throw ValidationException::withMessages([
                        'itens' => 'Um dos projetos selecionados não existe mais.',
                    ]);
                }

                $aplicados[] = $this->aplicarNoProjeto(
                    $projeto,
                    $item['area_id'] ?? null,
                    $item['subarea_id'] ?? null,
                );
            }

            return $aplicados;
        });
    }

    /**
     * Troca a classificação de um projeto validando cada sugestão contra o que
     * foi realmente sugerido nas avaliações concluídas.
     *
     * @return array<string, mixed>
     */
    private function aplicarNoProjeto(Projeto $projeto, ?int $areaId, ?int $subareaId): array
    {
        if ($areaId === null && $subareaId === null) {
            throw ValidationException::withMessages([
                'itens' => "Nenhuma sugestão foi escolhida para \"{$projeto->titulo}\".",
            ]);
        }

        $antes = ['area' => $projeto->area?->nome, 'subarea' => $projeto->subarea?->nome];

        if ($areaId !== null) {
            $this->garantirSugerido($projeto, 'area_sugerida_id', $areaId, 'área');
        }

        if ($subareaId !== null) {
            $this->garantirSugerido($projeto, 'subarea_sugerida_id', $subareaId, 'subárea');
        }

        $novaArea = $areaId ?? $projeto->area_id;
        $novaSubarea = $subareaId ?? $projeto->subarea_id;

        // Subárea pertence a uma área só: trocar a área sem trocar a subárea
        // deixaria o projeto numa combinação inexistente no catálogo.
        if ($novaSubarea !== null && ! Subarea::where('id', $novaSubarea)->where('area_id', $novaArea)->exists()) {
            $novaSubarea = null;
        }

        $projeto->update(['area_id' => $novaArea, 'subarea_id' => $novaSubarea]);
        $projeto->load(['area:id,nome', 'subarea:id,nome']);

        return [
            'projeto_id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'area_anterior' => $antes['area'],
            'area' => $projeto->area?->nome,
            'subarea_anterior' => $antes['subarea'],
            'subarea' => $projeto->subarea?->nome,
            // Avisa quando a subárea caiu junto por não pertencer à nova área.
            'subarea_limpa' => $antes['subarea'] !== null && $projeto->subarea_id === null,
        ];
    }

    /** Barra qualquer valor que não tenha sido sugerido por um avaliador do projeto. */
    private function garantirSugerido(Projeto $projeto, string $coluna, int $valor, string $rotulo): void
    {
        $sugerido = Avaliacao::where('projeto_id', $projeto->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->where($coluna, $valor)
            ->exists();

        if (! $sugerido) {
            throw ValidationException::withMessages([
                'itens' => "A {$rotulo} escolhida para \"{$projeto->titulo}\" não consta nas sugestões dos avaliadores.",
            ]);
        }
    }

    /**
     * Ranking dos projetos que já receberam ao menos uma avaliação concluída,
     * pela MÉDIA das notas finais (0 a 10). Desempate: mais avaliações primeiro
     * (média mais confiável) e, por fim, título.
     *
     * `completo` marca quem já atingiu o mínimo de avaliações — abaixo disso a
     * média ainda é parcial e não deve valer como classificação final.
     *
     * @param  array{area_id?:int|null, categoria?:string|null}  $filtros
     * @return list<array<string, mixed>>
     */
    public function rankingProjetos(array $filtros = []): array
    {
        // O ranking decide quem vai para a lista final, então o projeto-exemplo
        // de um orientador demo fica de fora. As listagens operacionais desta
        // mesma aba continuam mostrando tudo — lá o admin quer ver o que existe.
        $projetos = Projeto::semDemo()
            ->whereHas('avaliacoes', fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            // Categorias não competem entre si: FETEC Jr, FETECMS e FETECMS FUNDECT
            // têm regras próprias de equipe e de premiação.
            ->when($filtros['categoria'] ?? null, fn ($q, $categoria) => $q->where('categoria', $categoria))
            ->with([
                'area:id,nome',
                'avaliacoes' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
            ])
            ->get();

        $limites = Edicao::limites();

        $lista = $projetos->map(function (Projeto $p) use ($limites) {
            $concluidas = $p->avaliacoes;
            $total = $concluidas->count();

            return [
                'projeto_id' => $p->id,
                'titulo' => $p->titulo,
                'area' => $p->area?->nome,
                'categoria' => $p->categoria?->label(),
                'avaliacoes' => $total,
                'media' => round($concluidas->avg('nota'), 2),
                'medias_secoes' => $this->mediasPorSecao($concluidas),
                'completo' => $total >= $limites->minPorProjeto($p->categoria),
                'nota_maxima' => Avaliacao::notaMaxima(),
            ];
        })->all();

        usort($lista, fn ($a, $b) => [$b['media'], $b['avaliacoes'], $a['titulo']]
            <=> [$a['media'], $a['avaliacoes'], $b['titulo']]);

        // Posição atribuída depois da ordenação; empate na média divide o lugar.
        $posicao = 0;
        $anterior = null;
        foreach ($lista as $i => &$linha) {
            if ($linha['media'] !== $anterior) {
                $posicao = $i + 1;
                $anterior = $linha['media'];
            }
            $linha['posicao'] = $posicao;
        }

        return $lista;
    }

    /**
     * Média de cada seção pontuada da rubrica, com o teto da seção — mostra em
     * que o projeto foi bem ou mal, e não só a nota final. Conta apenas as
     * avaliações que já têm respostas: as anteriores à rubrica atual entram
     * somente na média geral.
     *
     * @param  Collection<int, Avaliacao>  $avaliacoes
     * @return list<array{chave:string, titulo:string, media:float|null, maximo:float}>
     */
    private function mediasPorSecao(Collection $avaliacoes): array
    {
        $respondidas = $avaliacoes->filter(fn (Avaliacao $a) => ! empty($a->respostas));

        return array_map(function (array $secao) use ($respondidas) {
            $pontos = $respondidas->map(
                fn (Avaliacao $a) => Rubrica::pontosDaSecao($secao['chave'], $a->respostas ?? []),
            );

            return [...$secao, 'media' => $pontos->isEmpty() ? null : round($pontos->avg(), 2)];
        }, Rubrica::secoesPontuadas());
    }

    /**
     * Designa um projeto submetido para avaliação, criando avaliações "designadas".
     * Alvo: um avaliador específico, ou todos os avaliadores de uma área/subárea.
     * Pula quem já tem esse projeto e pode exceder o teto de 5 (override do admin).
     *
     * @return int quantas designações novas foram criadas
     */
    public function designar(Projeto $projeto, string $tipo, ?int $alvoId, array $selecionados = []): int
    {
        $avaliadorIds = match ($tipo) {
            'avaliador' => [$alvoId],
            'area' => AvaliadorProfile::where('area_id', $alvoId)->pluck('user_id')->all(),
            'subarea' => AvaliadorProfile::where('subarea_id', $alvoId)->pluck('user_id')->all(),
            'comissao' => $this->idsDaComissao($selecionados),
            default => [],
        };

        $novas = 0;
        foreach ($avaliadorIds as $uid) {
            // `designacao_manual` protege a designação: o avaliador não consegue
            // sortear para fora um projeto que o admin colocou na fila dele.
            $avaliacao = Avaliacao::firstOrCreate(
                ['projeto_id' => $projeto->id, 'avaliador_id' => $uid],
                ['status' => StatusAvaliacao::Designada, 'designacao_manual' => true],
            );

            if ($avaliacao->wasRecentlyCreated) {
                $novas++;
            }
        }

        return $novas;
    }

    /**
     * Membros da comissão especial. Sem seleção, vai a comissão inteira; com
     * seleção, só os marcados — e quem não é da comissão é descartado.
     *
     * @param  list<int>  $selecionados
     * @return list<int>
     */
    private function idsDaComissao(array $selecionados): array
    {
        $ids = AvaliadorProfile::where('comissao_especial', true)
            ->when($selecionados !== [], fn ($q) => $q->whereIn('user_id', $selecionados))
            ->pluck('user_id')
            ->all();

        if ($ids === []) {
            throw ValidationException::withMessages([
                'tipo' => $selecionados === []
                    ? 'Nenhum avaliador está marcado como comissão especial.'
                    : 'Nenhum dos avaliadores selecionados é da comissão especial.',
            ]);
        }

        return $ids;
    }

    /** Define (ou remove, com null) o limite individual de avaliações do avaliador. */
    public function definirLimite(User $avaliador, ?int $limite): void
    {
        $avaliador->avaliadorProfile?->update(['limite_avaliacoes' => $limite]);
    }

    /** Marca/desmarca o avaliador como membro da comissão especial. */
    public function definirComissao(User $avaliador, bool $comissao): void
    {
        $avaliador->avaliadorProfile?->update(['comissao_especial' => $comissao]);
    }

    /**
     * Libera para o avaliador uma área (e, opcionalmente, uma subárea) além da
     * que ele escolheu. Só o admin faz isso. Repetir a mesma combinação não
     * duplica; a área do próprio cadastro é recusada (já vale por si).
     */
    public function adicionarAreaExtra(User $avaliador, int $areaId, ?int $subareaId): AvaliadorAreaExtra
    {
        $perfil = $avaliador->avaliadorProfile;

        if (! $perfil) {
            throw ValidationException::withMessages(['area_id' => 'Este avaliador ainda não tem perfil de avaliação.']);
        }

        if ($subareaId !== null && Subarea::where('id', $subareaId)->where('area_id', $areaId)->doesntExist()) {
            throw ValidationException::withMessages(['subarea_id' => 'A subárea escolhida não pertence a essa área.']);
        }

        if ($perfil->area_id === $areaId && $perfil->subarea_id === $subareaId) {
            throw ValidationException::withMessages(['area_id' => 'Essa já é a classificação do próprio avaliador.']);
        }

        return AvaliadorAreaExtra::firstOrCreate([
            'avaliador_profile_id' => $perfil->id,
            'area_id' => $areaId,
            'subarea_id' => $subareaId,
        ]);
    }

    /** Tira uma área extra do avaliador. */
    public function removerAreaExtra(User $avaliador, int $extraId): void
    {
        AvaliadorAreaExtra::where('id', $extraId)
            ->where('avaliador_profile_id', $avaliador->avaliadorProfile?->id)
            ->delete();
    }

    /** Marca/desmarca um avaliador como "demo" (fora do escopo real). */
    public function definirDemo(User $avaliador, bool $demo): void
    {
        $avaliador->update(['is_demo' => $demo]);
    }

    /** Apaga todas as avaliações dos avaliadores demo. Retorna quantas foram apagadas. */
    public function limparDadosDeTeste(): int
    {
        $demoIds = User::where('role', Role::Avaliador->value)
            ->where('is_demo', true)
            ->pluck('id');

        return Avaliacao::whereIn('avaliador_id', $demoIds)->delete();
    }

    /** Configuração do período de avaliação (liberação + encerramento). */
    public function config(): array
    {
        $edicao = Edicao::atual();
        $limites = LimitesAvaliacao::daEdicao($edicao);
        $data = $edicao?->avaliacao_liberada_em;   // Carbon no fuso do app
        $fim = $edicao?->avaliacao_encerrada_em;

        return [
            'liberada' => (bool) $edicao?->avaliacaoLiberada(),
            'encerrada' => (bool) $edicao?->avaliacaoEncerrada(),
            // Valores para <input type="datetime-local"> e rótulos dd/MM/aaaa HH:mm,
            // ambos no fuso do app (evita o shift de UTC do navegador).
            'liberada_em_input' => $data?->format('Y-m-d\TH:i'),
            'liberada_em_label' => $data?->format('d/m/Y H:i'),
            'encerrada_em_input' => $fim?->format('Y-m-d\TH:i'),
            'encerrada_em_label' => $fim?->format('d/m/Y H:i'),
            // Período de ajustes: a janela em que o orientador responde às
            // sugestões dos avaliadores (aba "Ajustes"). Sem data de início, a
            // aba fica fechada.
            'ajustes_abertos' => (bool) $edicao?->ajustesAbertos(),
            'ajustes_de_input' => $edicao?->ajustes_de?->format('Y-m-d\TH:i'),
            'ajustes_de_label' => $edicao?->ajustes_de?->format('d/m/Y H:i'),
            'ajustes_ate_input' => $edicao?->ajustes_ate?->format('Y-m-d\TH:i'),
            'ajustes_ate_label' => $edicao?->ajustes_ate?->format('d/m/Y H:i'),
            // Limites do edital: quantas avaliações cada avaliador conclui (e
            // quantos projetos ele vê na tela), até quantas ele pode receber, e
            // o par mínimo/máximo de cada projeto — geral e por categoria.
            'min_por_avaliador' => $limites->minPorAvaliador(),
            'max_por_avaliador' => $limites->maxPorAvaliador(),
            'min_por_projeto' => $limites->minPorProjeto(),
            'max_por_projeto' => $limites->maxPorProjeto(),
            'categorias' => $limites->categorias(),
            'limite_maximo' => LimitesAvaliacao::MAXIMO,
        ];
    }

    /**
     * Grava os limites da avaliação online. Só as chaves enviadas mudam — cada
     * card da tela salva o seu bloco.
     *
     * @param  array{min_por_avaliador?:int, max_por_avaliador?:int, min_por_projeto?:int, max_por_projeto?:int, categorias?:array<string, array{min:int|null, max:int|null}>}  $dados
     */
    public function definirMinimos(array $dados, User $admin): array
    {
        $edicao = Edicao::atual();

        // Chave ausente = card não salvo agora. Já um `max_por_avaliador` nulo
        // é intencional: quer dizer "sem teto".
        $mudancas = [
            'avaliacoes_min_por_avaliador' => [TipoRegistro::AvaliacaoMinAvaliador, 'min_por_avaliador'],
            'avaliacoes_max_por_avaliador' => [TipoRegistro::AvaliacaoMaxAvaliador, 'max_por_avaliador'],
            'avaliacoes_min_por_projeto' => [TipoRegistro::AvaliacaoMinProjeto, 'min_por_projeto'],
            'avaliacoes_max_por_projeto' => [TipoRegistro::AvaliacaoMaxProjeto, 'max_por_projeto'],
        ];

        foreach ($mudancas as $coluna => [$tipo, $chave]) {
            if (! array_key_exists($chave, $dados)) {
                continue;
            }

            $novo = $dados[$chave];
            $anterior = $edicao?->{$coluna};
            $edicao?->update([$coluna => $novo]);

            $this->registrarParametro(
                $tipo,
                $admin,
                $anterior === null ? 'sem teto' : (string) $anterior,
                $novo === null ? 'sem teto' : (string) $novo,
            );
        }

        if (array_key_exists('categorias', $dados)) {
            $antes = Edicao::limites()->resumoCategorias();
            $edicao?->update(['avaliacoes_por_categoria' => $dados['categorias']]);

            $this->registrarParametro(
                TipoRegistro::AvaliacaoLimitesCategoria,
                $admin,
                $antes,
                Edicao::limites()->resumoCategorias(),
            );
        }

        return $this->config();
    }

    /**
     * Configuração do algoritmo de distribuição (aba Avaliação Online): a regra
     * de cada categoria mais o que a tela precisa para desenhar o formulário.
     *
     * @return array{regras: array<string, array{ativa:bool, min_concluidas:int, max_concluidas:int|null}>, categorias: array<int, array{value:string, label:string}>, max_concluidas: int}
     */
    public function configDistribuicao(): array
    {
        return [
            'regras' => Edicao::regrasDistribuicao()->toArray(),
            'categorias' => Categoria::opcoes(),
            'max_concluidas' => RegrasDistribuicao::MAX_CONCLUIDAS,
            'ao_cadastrar' => Edicao::distribuiAoCadastrar(),
        ];
    }

    /**
     * Grava as regras do algoritmo. Chega a configuração inteira (as três
     * categorias de uma vez), como o formulário da tela salva.
     *
     * @param  array<string, mixed>  $regras
     */
    public function definirRegrasDistribuicao(array $regras, User $admin): array
    {
        $anterior = Edicao::regrasDistribuicao();
        $novas = new RegrasDistribuicao($regras);

        Edicao::atual()?->update(['distribuicao_regras' => $novas->toArray()]);

        $this->registrarParametro(
            TipoRegistro::AvaliacaoRegraDistribuicao,
            $admin,
            $anterior->resumo(),
            $novas->resumo(),
        );

        return $this->configDistribuicao();
    }

    /**
     * Liga/desliga a designação automática para quem acaba de se cadastrar como
     * avaliador (toggle do Algoritmo de distribuição).
     */
    public function definirDistribuicaoAoCadastrar(bool $ativo, User $admin): array
    {
        $anterior = Edicao::distribuiAoCadastrar();

        Edicao::atual()?->update(['distribuicao_ao_cadastrar' => $ativo]);

        $this->registrarParametro(
            TipoRegistro::AvaliacaoDesignacaoAoCadastrar,
            $admin,
            $anterior ? 'ligada' : 'desligada',
            $ativo ? 'ligada' : 'desligada',
        );

        return $this->configDistribuicao();
    }

    /** Anota a mudança de parâmetro na trilha (seção "Avaliação Online"). */
    private function registrarParametro(TipoRegistro $tipo, User $admin, ?string $de, ?string $para): void
    {
        if ($de === $para) {
            return; // salvou sem mudar nada: não vira registro
        }

        $this->registros->parametroAvaliacao($tipo, $admin, $de, $para);
    }

    /** Define a data de liberação (ou remove, com null) na edição atual. */
    public function definirLiberacao(?string $data, User $admin): array
    {
        // A data chega como "hora de parede" local (ex.: 2026-08-17T07:00) e é
        // interpretada no fuso do app — 07:00 é 07:00 em Campo Grande, sem shift.
        $valor = ($data !== null && $data !== '')
            ? Carbon::parse($data, config('app.timezone'))
            : null;

        $fim = Edicao::atual()?->avaliacao_encerrada_em;
        if ($valor && $fim && $valor->greaterThanOrEqualTo($fim)) {
            throw ValidationException::withMessages([
                'liberada_em' => 'A liberação precisa ser antes do encerramento da avaliação ('.$fim->format('d/m/Y H:i').').',
            ]);
        }

        $anterior = Edicao::atual()?->avaliacao_liberada_em;
        Edicao::atual()?->update(['avaliacao_liberada_em' => $valor]);

        $this->registrarParametro(
            TipoRegistro::AvaliacaoLiberacao,
            $admin,
            $anterior?->format('d/m/Y H:i'),
            $valor?->format('d/m/Y H:i'),
        );

        return $this->config();
    }

    /** Define a data de encerramento da avaliação (ou remove, com null). */
    public function definirEncerramento(?string $data, User $admin): array
    {
        $valor = ($data !== null && $data !== '')
            ? Carbon::parse($data, config('app.timezone'))
            : null;

        $inicio = Edicao::atual()?->avaliacao_liberada_em;
        if ($valor && $inicio && $valor->lessThanOrEqualTo($inicio)) {
            throw ValidationException::withMessages([
                'encerrada_em' => 'O encerramento precisa ser depois da liberação da avaliação ('.$inicio->format('d/m/Y H:i').').',
            ]);
        }

        $anterior = Edicao::atual()?->avaliacao_encerrada_em;
        Edicao::atual()?->update(['avaliacao_encerrada_em' => $valor]);

        $this->registrarParametro(
            TipoRegistro::AvaliacaoEncerramento,
            $admin,
            $anterior?->format('d/m/Y H:i'),
            $valor?->format('d/m/Y H:i'),
        );

        return $this->config();
    }

    /**
     * Define o início ou o fim do período de ajustes (ou remove, com null). É a
     * janela da aba "Ajustes" do orientador — sem início, ela fica fechada.
     */
    public function definirAjustes(string $ponta, ?string $data, User $admin): array
    {
        $valor = ($data !== null && $data !== '')
            ? Carbon::parse($data, config('app.timezone'))
            : null;

        $edicao = Edicao::atual();
        $coluna = $ponta === 'de' ? 'ajustes_de' : 'ajustes_ate';
        $outra = $ponta === 'de' ? $edicao?->ajustes_ate : $edicao?->ajustes_de;

        if ($valor && $outra) {
            $foraDeOrdem = $ponta === 'de'
                ? $valor->greaterThanOrEqualTo($outra)
                : $valor->lessThanOrEqualTo($outra);

            if ($foraDeOrdem) {
                throw ValidationException::withMessages([
                    $coluna => $ponta === 'de'
                        ? 'O início dos ajustes precisa ser antes do fim ('.$outra->format('d/m/Y H:i').').'
                        : 'O fim dos ajustes precisa ser depois do início ('.$outra->format('d/m/Y H:i').').',
                ]);
            }
        }

        $anterior = $edicao?->{$coluna};
        $edicao?->update([$coluna => $valor]);

        $this->registrarParametro(
            $ponta === 'de' ? TipoRegistro::AvaliacaoAjustesInicio : TipoRegistro::AvaliacaoAjustesFim,
            $admin,
            $anterior?->format('d/m/Y H:i'),
            $valor?->format('d/m/Y H:i'),
        );

        return $this->config();
    }

    /** Ordena os grupos por nome da área (mantendo "Sem área" no fim). */
    private function ordenarPorArea(array $grupos): array
    {
        $lista = array_values($grupos);
        usort($lista, function ($a, $b) {
            if ($a['area_id'] === 0) {
                return 1;
            }
            if ($b['area_id'] === 0) {
                return -1;
            }

            return strcmp($a['area'], $b['area']);
        });

        return $lista;
    }
}
