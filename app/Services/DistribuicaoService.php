<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\StatusDistribuicao;
use App\Jobs\ProcessarDistribuicao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Distribuicao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\LimitesAvaliacao;
use App\Support\RegrasDistribuicao;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Distribuição automática de projetos para avaliação (E7).
 *
 * Guloso com balanceamento de carga, idempotente (re-executável): só COMPLETA
 * cada projeto até o alvo, sem tocar em avaliações já existentes. Casa por
 * subárea (preferencial) → área → área IRMÃ (mesmo grupo de correlação, só
 * quando a própria área se esgota). Ignora avaliadores demo, inativos e sem
 * área; respeita o limite individual de cada avaliador. Projetos que não
 * fecham o alvo entram no relatório de "sub-cobertos" para o admin resolver.
 *
 * Antes de tudo, o bolo de projetos passa pelas {@see RegrasDistribuicao} que o
 * admin configurou (Avaliação Online → Algoritmo de distribuição): categoria
 * fora da regra, ou fora da faixa de avaliações concluídas, nem entra na conta.
 */
class DistribuicaoService
{
    public function __construct(private readonly FilaAvaliadorService $fila) {}

    /**
     * Enfileira uma rodada e devolve o registro que a tela vai consultar.
     *
     * Uma rodada em andamento **bloqueia outra**: duas distribuições
     * simultâneas competiriam pelas mesmas vagas e o relatório de nenhuma delas
     * faria sentido.
     */
    public function enfileirar(string $tipo, ?User $autor = null): Distribuicao
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'distribuicao' => 'Nenhuma edição em curso para distribuir.',
            ]);
        }

        $emAndamento = Distribuicao::where('edicao_id', $edicao->id)
            ->whereIn('status', [StatusDistribuicao::Pendente->value, StatusDistribuicao::Processando->value])
            ->first();

        if ($emAndamento !== null) {
            throw ValidationException::withMessages([
                'distribuicao' => 'Já existe uma distribuição em andamento. Aguarde o fim dela.',
            ]);
        }

        $rodada = Distribuicao::create([
            'edicao_id' => $edicao->id,
            'tipo' => $tipo === Distribuicao::TIPO_REDISTRIBUIR
                ? Distribuicao::TIPO_REDISTRIBUIR
                : Distribuicao::TIPO_DISTRIBUIR,
            'status' => StatusDistribuicao::Pendente,
            'iniciada_por' => $autor?->id,
        ]);

        ProcessarDistribuicao::dispatch($rodada->id);

        return $rodada;
    }

    /** A rodada mais recente da edição — é o que a tela mostra ao abrir. */
    public function ultimaRodada(): ?Distribuicao
    {
        $edicao = Edicao::atual();

        return $edicao === null
            ? null
            : Distribuicao::where('edicao_id', $edicao->id)->latest('id')->first();
    }

    /**
     * @param  ?callable(int, int): void  $progresso  recebe (processados, total) a
     *                                                cada projeto resolvido — é o
     *                                                que alimenta a barra da tela.
     * @return array{designadas_criadas:int, ignorados_pela_regra:int, sub_cobertos: array<int, array{projeto_id:int, titulo:string, area:?string, faltam:int}>}
     */
    public function distribuir(?callable $progresso = null): array
    {
        // Limites parametrizados pelo admin. O alvo de designações sai do
        // Algoritmo de distribuição e o teto, de Parametrização → Avaliação
        // Online; os dois pela categoria do projeto.
        $limites = Edicao::limites();

        $avaliadores = $this->carregarAvaliadores($limites);
        [$cargaInicial, $projetoInfo] = $this->estadoAtual($avaliadores);

        // Aplica a carga já existente e monta índice de avaliadores por área. O
        // avaliador aparece na sua área e em cada área extra liberada pelo admin.
        $porArea = [];
        foreach ($avaliadores as $id => $av) {
            $avaliadores[$id]['carga'] = $cargaInicial[$id] ?? 0;
            foreach ($av['areas'] as $areaId) {
                $porArea[$areaId][] = $id;
            }
        }

        // Regra do admin: o que não passa por ela fica fora desta rodada.
        $regras = Edicao::regrasDistribuicao();
        $candidatos = $this->carregarProjetos($projetoInfo);
        $projetos = array_values(array_filter(
            $candidatos,
            fn (array $p) => $regras->aceita($p['categoria'], $p['concluidas']),
        ));
        $ignorados = count($candidatos) - count($projetos);

        $correlatas = $this->mapaCorrelatas();

        // Disponíveis dentre uma lista: com folga no limite, abaixo do teto da
        // rodada e ainda não designados para este projeto.
        $disponiveis = function (array $ids, array $proj, int $tetoRodada) use (&$avaliadores): array {
            return array_values(array_filter(
                $ids,
                fn ($id) => $avaliadores[$id]['carga'] < min($avaliadores[$id]['capacidade'], $tetoRodada)
                    && ! isset($proj['assigned'][$id])
            ));
        };

        // Avaliadores das áreas irmãs do projeto (vazio quando a área não tem grupo).
        $irmaos = fn (array $proj) => array_merge(
            ...array_map(fn ($areaId) => $porArea[$areaId] ?? [], $correlatas[$proj['area_id']] ?? []),
        );

        // Elegíveis de um projeto: a própria área manda; só quando ela se esgota
        // o projeto cai para as áreas irmãs (mesmo grupo de correlação).
        $elegiveis = function (array $proj, int $tetoRodada) use ($disponiveis, $irmaos, $porArea): array {
            $proprios = $disponiveis($porArea[$proj['area_id']] ?? [], $proj, $tetoRodada);

            return $proprios !== [] ? $proprios : $disponiveis($irmaos($proj), $proj, $tetoRodada);
        };

        // Ordena os projetos por escassez (menos elegíveis primeiro; empate: menor
        // cobertura). A escassez olha o pool inteiro — própria área + irmãs —, que é
        // de onde o projeto pode de fato ser servido, e ignora o teto de rodada.
        foreach ($projetos as &$p) {
            $p['elegiveis_ini'] = count($disponiveis($porArea[$p['area_id']] ?? [], $p, PHP_INT_MAX))
                + count($disponiveis($irmaos($p), $p, PHP_INT_MAX));
        }
        unset($p);
        usort($projetos, fn ($a, $b) => ($a['elegiveis_ini'] <=> $b['elegiveis_ini']) ?: ($a['coverage'] <=> $b['coverage']));

        $novas = [];
        $subCobertos = [];
        $total = count($projetos);
        $feitos = 0;
        // Relator que já engole o caso "sem callback", para o laço abaixo não
        // ficar salpicado de ifs.
        $relatar = fn (int $feitos) => $progresso === null ? null : $progresso($feitos, $total);
        $relatar(0);

        // Preferência dentro de um projeto: subárea igual → menor carga → id
        // (desempate estável).
        $melhor = function (array $cands, array $proj) use (&$avaliadores): int {
            $casaSubarea = fn ($id) => $proj['subarea_id'] !== null
                && in_array([$proj['area_id'], $proj['subarea_id']], $avaliadores[$id]['pares'], true) ? 1 : 0;

            usort($cands, function ($x, $y) use (&$avaliadores, $casaSubarea) {
                return ($casaSubarea($y) <=> $casaSubarea($x))
                    ?: (($avaliadores[$x]['carga'] <=> $avaliadores[$y]['carga']) ?: ($x <=> $y));
            });

            return $cands[0];
        };

        // --- Rodadas iguais ------------------------------------------------
        // Ninguém recebe o (k+1)-ésimo projeto antes de todos os avaliadores
        // elegíveis terem k: o teto de carga sobe **uma unidade por passada**
        // sobre a lista inteira de projetos. Antes disso a distribuição enchia
        // a fila de um avaliador até o piso antes de olhar para o próximo, e a
        // feira terminava com gente em 6 e gente em 0.
        //
        // A igualdade é a possível dentro do casamento por área: avaliador de
        // Exatas não recebe projeto de Humanas para "empatar" a carga.
        $capacidadeMax = $avaliadores === []
            ? 0
            : max(array_column($avaliadores, 'capacidade'));

        for ($tetoRodada = 1; $tetoRodada <= $capacidadeMax; $tetoRodada++) {
            $criou = false;

            foreach ($projetos as &$proj) {
                if ($proj['pronto'] ?? false) {
                    continue;
                }

                // Quantos avaliadores este projeto deve receber. Pode ser mais
                // do que a cobertura exige — designar exatamente o mínimo deixa
                // o resultado na mão de quem não abre a avaliação —, e o valor
                // já vem preso ao máximo por projeto da categoria.
                $alvo = $limites->designacoesPorProjeto($proj['categoria']);

                while ($proj['coverage'] < $alvo) {
                    $cands = $elegiveis($proj, $tetoRodada);
                    if ($cands === []) {
                        break;
                    }

                    $escolhido = $melhor($cands, $proj);
                    $avaliadores[$escolhido]['carga']++;
                    $proj['coverage']++;
                    $proj['assigned'][$escolhido] = true;
                    $criou = true;

                    $novas[] = [
                        'projeto_id' => $proj['id'],
                        'avaliador_id' => $escolhido,
                        'status' => StatusAvaliacao::Designada->value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($proj['coverage'] >= $alvo) {
                    $proj['pronto'] = true;
                    $relatar(++$feitos);
                }
            }
            unset($proj);

            // Uma passada inteira sem designar nada: subir o teto não vai
            // desbloquear ninguém, o que falta é avaliador.
            if (! $criou) {
                break;
            }
        }

        // O que não fechou o alvo entra no relatório para o admin resolver —
        // mas só é **sub-coberto** quem ficou abaixo do MÍNIMO da categoria.
        // Não alcançar o alvo de designações, tendo a cobertura fechada, é
        // apenas sobra que não coube: o projeto vai ser avaliado do mesmo jeito.
        foreach ($projetos as $proj) {
            if ($proj['pronto'] ?? false) {
                continue;
            }

            $faltam = $limites->minPorProjeto($proj['categoria']) - $proj['coverage'];

            if ($faltam > 0) {
                $subCobertos[] = [
                    'projeto_id' => $proj['id'],
                    'titulo' => $proj['titulo'],
                    'area' => $proj['area_nome'],
                    'faltam' => $faltam,
                ];
            }

            $relatar(++$feitos);
        }

        if ($novas !== []) {
            DB::table('avaliacoes')->insert($novas);
        }

        return [
            'designadas_criadas' => count($novas),
            'ignorados_pela_regra' => $ignorados,
            'sub_cobertos' => $subCobertos,
        ];
    }

    /**
     * Redistribui a rodada: cada avaliador devolve ao bolo os projetos que
     * ainda não abriu e recebe outros no lugar, pelas mesmas prioridades do
     * edital. O que está em avaliação ou concluído não se mexe, e a designação
     * manual do admin também fica de pé. Sem alternativa, um projeto devolvido
     * pode voltar para quem o tinha — a fila nunca encolhe por causa do rodízio.
     *
     * Depois do rodízio, uma passada da distribuição normal completa os
     * projetos que ficaram abaixo do alvo e devolve o relatório de sub-cobertura.
     *
     * @param  ?callable(int, int, string): void  $progresso  recebe (processados,
     *                                                        total, etapa). São duas etapas:
     *                                                        o rodízio, avaliador a avaliador,
     *                                                        e a passada de cobertura.
     * @return array{devolvidas:int, recebidas:int, designadas_criadas:int, ignorados_pela_regra:int, sub_cobertos: array<int, array{projeto_id:int, titulo:string, area:?string, faltam:int}>}
     */
    public function redistribuir(?callable $progresso = null): array
    {
        return DB::transaction(function () use ($progresso) {
            $devolvidas = 0;
            $recebidas = 0;

            $avaliadores = User::query()
                ->where('role', Role::Avaliador->value)
                ->where('is_active', true)
                ->where('is_demo', false)
                ->with('avaliadorProfile.areasExtras')
                ->get();

            // A barra cobre as duas etapas de uma vez: o rodízio (um passo por
            // avaliador) e a passada de cobertura (um passo por projeto). O
            // total da segunda só é conhecido quando ela começa, então o
            // denominador é atualizado no caminho.
            $totalAvaliadores = $avaliadores->count();
            $feitos = 0;
            $etapaRodizio = 'Trocando as designações não abertas';
            $relatar = fn (int $feitos) => $progresso === null
                ? null
                : $progresso($feitos, $totalAvaliadores, $etapaRodizio);
            $relatar(0);

            foreach ($avaliadores as $avaliador) {
                $rodizio = $this->fila->roletar($avaliador);
                $devolvidas += $rodizio['trocados'];
                // Quem estava com a fila curta (ou vazia) completa aqui.
                $recebidas += $rodizio['recebidos'] + $this->fila->repor($avaliador);
                $relatar(++$feitos);
            }

            $cobertura = $this->distribuir($progresso === null ? null : function (int $feitos, int $total) use ($progresso) {
                $progresso($feitos, $total, 'Completando a cobertura dos projetos');
            });

            return [
                'devolvidas' => $devolvidas,
                'recebidas' => $recebidas,
                'designadas_criadas' => $recebidas + $cobertura['designadas_criadas'],
                'ignorados_pela_regra' => $cobertura['ignorados_pela_regra'],
                'sub_cobertos' => $cobertura['sub_cobertos'],
            ];
        });
    }

    /**
     * Escolhe UM avaliador para um projeto, pelas mesmas prioridades do edital
     * (área+subárea → área → área irmã) e, dentro da faixa, o de menor carga.
     * É o que repõe a cobertura quando o admin retira uma designação.
     *
     * Devolve null quando ninguém cabe: projeto no teto de avaliadores, área
     * sem gente livre ou todo mundo com a fila cheia. Nesse caso o projeto fica
     * sub-coberto de propósito — a saída é a designação manual, que é a única
     * que passa por cima dos limites.
     *
     * @param  list<int>  $excluir  avaliadores que não podem receber (quem acabou de sair, por exemplo)
     */
    public function designarUm(Projeto $projeto, array $excluir = []): ?User
    {
        $limites = Edicao::limites();

        $jaTem = Avaliacao::where('projeto_id', $projeto->id)->pluck('avaliador_id')->all();

        if (count($jaTem) >= $limites->maxPorProjeto($projeto->categoria)) {
            return null;
        }

        $correlatas = $this->mapaCorrelatas()[$projeto->area_id] ?? [];

        $candidatos = User::query()
            ->where('role', Role::Avaliador->value)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereNotIn('id', [...$jaTem, ...$excluir])
            ->with('avaliadorProfile.areasExtras')
            ->withCount('avaliacoes as carga_count')
            ->get()
            ->filter(function (User $u) use ($limites) {
                $perfil = $u->avaliadorProfile;
                $carga = (int) $u->carga_count;

                return $perfil !== null
                    && $perfil->areasAtendidas() !== []
                    && $carga < min(array_filter([
                        $perfil->limite_avaliacoes ?? $limites->minPorAvaliador(),
                        $limites->maxPorAvaliador(),
                    ], fn ($v) => $v !== null));
            });

        $faixas = [
            fn (User $u) => $projeto->subarea_id !== null
                && in_array([$projeto->area_id, $projeto->subarea_id], $u->avaliadorProfile->paresAtendidos(), true),
            fn (User $u) => in_array($projeto->area_id, $u->avaliadorProfile->areasAtendidas(), true),
            fn (User $u) => array_intersect($correlatas, $u->avaliadorProfile->areasAtendidas()) !== [],
        ];

        foreach ($faixas as $faixa) {
            $naFaixa = $candidatos->filter($faixa);

            if ($naFaixa->isNotEmpty()) {
                return $naFaixa->sortBy(fn (User $u) => [(int) $u->carga_count, $u->id])->first();
            }
        }

        return null;
    }

    /**
     * Mapa área → áreas IRMÃS (as outras do mesmo grupo de correlação). Área sem
     * grupo não aparece no mapa: ela nunca recebe avaliador de fora.
     *
     * @return array<int, list<int>>
     */
    private function mapaCorrelatas(): array
    {
        $mapa = [];

        Area::query()
            ->whereNotNull('grupo_correlato')
            ->get(['id', 'grupo_correlato'])
            ->groupBy(fn (Area $a) => $a->grupo_correlato->value)
            ->each(function ($areas) use (&$mapa) {
                $ids = $areas->pluck('id')->all();

                foreach ($ids as $id) {
                    $mapa[$id] = array_values(array_diff($ids, [$id]));
                }
            });

        return $mapa;
    }

    /**
     * Avaliadores elegíveis: ativos, não-demo e com ao menos uma área (a própria
     * ou uma liberada pelo admin). Cada rodada entrega no máximo o tamanho da
     * fila (o mínimo por avaliador) — ou o bloqueio individual, quando houver —
     * e nunca passa do teto total da edição.
     */
    private function carregarAvaliadores(LimitesAvaliacao $limites): array
    {
        $avaliadores = [];

        User::query()
            ->where('role', Role::Avaliador->value)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->with(['avaliadorProfile:id,user_id,area_id,subarea_id,limite_avaliacoes', 'avaliadorProfile.areasExtras'])
            ->get(['id'])
            ->each(function (User $u) use (&$avaliadores, $limites) {
                $perfil = $u->avaliadorProfile;
                $areas = $perfil?->areasAtendidas() ?? [];

                if ($areas === []) {
                    return; // sem área nenhuma não participa da distribuição automática
                }

                $avaliadores[$u->id] = [
                    'id' => $u->id,
                    // A própria área mais as extras liberadas pelo admin.
                    'areas' => $areas,
                    'pares' => $perfil->paresAtendidos(),
                    'capacidade' => min(array_filter([
                        $perfil->limite_avaliacoes ?? $limites->minPorAvaliador(),
                        $limites->maxPorAvaliador(),
                    ], fn ($v) => $v !== null)),
                    'carga' => 0,
                ];
            });

        return $avaliadores;
    }

    /**
     * Carga atual por avaliador real e, por projeto, cobertura (avaliações de
     * avaliadores reais) + conjunto de já designados (real + demo, p/ dedupe).
     *
     * @return array{0: array<int,int>, 1: array<int, array{coverage:int, assigned: array<int,bool>}>}
     */
    private function estadoAtual(array $avaliadores): array
    {
        $carga = [];
        $projeto = [];

        Avaliacao::query()->get(['projeto_id', 'avaliador_id'])->each(function ($a) use ($avaliadores, &$carga, &$projeto) {
            $real = isset($avaliadores[$a->avaliador_id]);
            if ($real) {
                $carga[$a->avaliador_id] = ($carga[$a->avaliador_id] ?? 0) + 1;
            }
            $projeto[$a->projeto_id]['coverage'] = ($projeto[$a->projeto_id]['coverage'] ?? 0) + ($real ? 1 : 0);
            $projeto[$a->projeto_id]['assigned'][$a->avaliador_id] = true;
        });

        return [$carga, $projeto];
    }

    private function carregarProjetos(array $projetoInfo): array
    {
        // `semDemo`: o projeto-exemplo de um orientador demo não é sorteado
        // para avaliador de verdade — quem o avalia é designado à mão.
        return Projeto::semDemo()
            ->where('status', ProjetoStatus::Submetido->value)
            ->select(['id', 'titulo', 'area_id', 'subarea_id', 'categoria'])
            ->withCount(['avaliacoes as concluidas_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value)])
            ->with('area:id,nome')
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'area_id' => $p->area_id,
                'subarea_id' => $p->subarea_id,
                'area_nome' => $p->area?->nome,
                'categoria' => $p->categoria,
                'concluidas' => (int) $p->concluidas_count,
                'coverage' => $projetoInfo[$p->id]['coverage'] ?? 0,
                'assigned' => $projetoInfo[$p->id]['assigned'] ?? [],
            ])
            ->all();
    }
}
