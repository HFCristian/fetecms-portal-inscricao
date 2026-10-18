<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\Turno;
use App\Models\Area;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\MapaLayout;
use App\Models\User;
use App\Support\JanelaTurnos;
use App\Support\PlantaEvento;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comitê especial → **Acompanhamento das avaliações**.
 *
 * Durante a feira, quem coordena o comitê pergunta duas coisas: **onde está
 * cada avaliador da comissão** (o que ele está avaliando agora, o que ainda
 * tem designado, quanto já fez) e **que estandes o comitê já cobriu**. A tela
 * responde com uma lista por avaliador e um mapa do evento que só enxerga as
 * avaliações do comitê, com um dashboard ao lado.
 *
 * "Avaliação do comitê" é a avaliação **no estande** feita por um avaliador
 * marcado como comissão especial — a mesma definição do painel da tela cheia
 * do Mapa do Evento (Sprint 200), para os dois números nunca discordarem. Vale
 * qualquer vaga: o avaliador da comissão que entrou numa vaga comum continua
 * sendo o comitê trabalhando, e a vaga vai escrita em cada linha. A designação
 * online (Comitê → Designações) fica de fora: na avaliação online não há como
 * separar o que veio do comitê do resto da fila do avaliador.
 *
 * Conta de ensaio (`is_demo`) não entra: o acompanhamento é do evento de
 * verdade.
 */
class AcompanhamentoComiteService
{
    public function __construct(private readonly LocaisFinalistasService $locais) {}

    /**
     * A lista por avaliador e o mapa de um turno.
     *
     * Sem turno pedido, o turno em foco da agenda (o que está acontecendo,
     * senão o próximo); sem agenda, o A.
     *
     * @return array<string, mixed>
     */
    public function painel(?string $turno = null): array
    {
        $edicao = Edicao::atual();
        $janela = JanelaTurnos::daEdicao();
        $foco = $janela->emFoco();
        $turnoMapa = Turno::tryFrom((string) $turno)
            ?? Turno::tryFrom((string) ($foco['turno'] ?? ''))
            ?? Turno::A;

        $membros = $this->membros();
        $avaliacoes = $edicao === null || $membros->isEmpty()
            ? collect()
            : AvaliacaoPresencial::where('edicao_id', $edicao->id)
                ->whereIn('avaliador_id', $membros->keys())
                ->with(['projeto:id,titulo,categoria,area_id', 'projeto.area:id,nome,sigla'])
                ->orderBy('id')
                ->get()
                ->filter(fn (AvaliacaoPresencial $a) => $a->projeto !== null);

        $locais = $this->locais->todos();
        $linhas = $avaliacoes->map(fn (AvaliacaoPresencial $a) => $this->linha($a, $locais, $membros, $janela));

        return [
            'turno' => $turnoMapa->value,
            'turno_label' => $turnoMapa->label(),
            'turnos' => Turno::opcoes(),
            'turno_em_andamento' => ($foco['situacao'] ?? null) === 'em_andamento' || ($foco['situacao'] ?? null) === 'margem'
                ? $foco['turno']
                : null,
            'vagas_comite' => $janela->comitePorProjeto(),
            'avaliadores' => $this->porAvaliador($membros, $linhas),
            'mapa' => $this->mapa($edicao, $turnoMapa, $linhas, $locais),
            'areas' => Area::orderBy('nome')->get(['id', 'nome', 'sigla'])
                ->map(fn (Area $a) => ['id' => $a->id, 'nome' => $a->nome, 'sigla' => $a->siglaDaLista()])
                ->all(),
            'atualizado_em' => now()->toIso8601String(),
        ];
    }

    /**
     * Os avaliadores da comissão especial: ativos, de verdade, com o perfil.
     *
     * @return Collection<int, User>
     */
    private function membros(): Collection
    {
        return User::where('role', Role::Avaliador->value)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereHas('avaliadorProfile', fn ($p) => $p->where('comissao_especial', true))
            ->with('avaliadorProfile.area:id,nome')
            ->orderBy('name')
            ->get()
            ->keyBy('id');
    }

    /**
     * Uma avaliação do comitê como a tela lê.
     *
     * A situação separa o que **ocupa** o avaliador (em avaliação, designada)
     * do que já passou: a avaliação aberta cujo turno acabou sem envio não é
     * mais trabalho em curso — some do "avaliando agora" e aparece como prazo
     * encerrado.
     *
     * @param  array<int, array<string, mixed>>  $locais
     * @param  Collection<int, User>  $membros
     * @return array<string, mixed>
     */
    private function linha(AvaliacaoPresencial $a, array $locais, Collection $membros, JanelaTurnos $janela): array
    {
        $projeto = $a->projeto;
        $local = $locais[$projeto->id] ?? null;

        $situacao = match (true) {
            $a->concluida() => 'concluida',
            $a->expirada($janela) => 'expirada',
            $a->status === StatusAvaliacao::EmAndamento => 'em_avaliacao',
            default => 'designada',
        };

        return [
            'avaliacao_id' => $a->id,
            'avaliador_id' => $a->avaliador_id,
            'avaliador' => $membros->get($a->avaliador_id)?->name,
            'projeto_id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'codigo' => $local['codigo'] ?? null,
            'estande' => $local['estande'] ?? null,
            'turno' => $local['turno'] ?? $a->turno?->value,
            'turno_label' => $local['turno_label'] ?? $a->turno?->label(),
            'area' => $projeto->area?->nome,
            'area_sigla' => $projeto->area?->siglaDaLista(),
            'categoria' => $projeto->categoria?->label(),
            'vaga' => $a->vaga?->value,
            'vaga_label' => $a->vaga?->label(),
            'situacao' => $situacao,
            'iniciada_em' => $a->iniciada_em?->toIso8601String(),
            'concluida_em' => $a->concluida_em?->toIso8601String(),
            'nota' => $a->concluida() && $a->nota !== null ? round((float) $a->nota, 3) : null,
        ];
    }

    /**
     * Uma entrada por membro do comitê — inclusive quem ainda não avaliou nada,
     * que é justamente quem a coordenação precisa achar.
     *
     * @param  Collection<int, User>  $membros
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @return list<array<string, mixed>>
     */
    private function porAvaliador(Collection $membros, Collection $linhas): array
    {
        $doAvaliador = $linhas->groupBy('avaliador_id');

        return $membros->map(function (User $u) use ($doAvaliador) {
            $suas = $doAvaliador->get($u->id, collect());
            $de = fn (string $situacao) => $suas->where('situacao', $situacao)->values();

            $emAvaliacao = $de('em_avaliacao');
            $designadas = $de('designada');
            $concluidas = $de('concluida')->sortByDesc('concluida_em')->values();

            return [
                'id' => $u->id,
                'nome' => $u->name,
                'email' => $u->email,
                'area' => $u->avaliadorProfile?->area?->nome,
                'apenas_presencial' => (bool) $u->avaliadorProfile?->apenas_presencial,
                'situacao' => match (true) {
                    $emAvaliacao->isNotEmpty() => 'avaliando',
                    $designadas->isNotEmpty() => 'designado',
                    default => 'livre',
                },
                'em_avaliacao' => $emAvaliacao->all(),
                'designadas' => $designadas->all(),
                'concluidas' => $concluidas->all(),
                'expiradas' => $de('expirada')->count(),
                'ultima_concluida_em' => $concluidas->first()['concluida_em'] ?? null,
            ];
        })->values()->all();
    }

    /**
     * A planta com, em cada estande do turno, o que o comitê fez ali.
     *
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @param  array<int, array<string, mixed>>  $locais
     * @return array<string, mixed>
     */
    private function mapa(?Edicao $edicao, Turno $turno, Collection $linhas, array $locais): array
    {
        $planta = ($edicao === null ? null : MapaLayout::vigente($edicao))?->dados ?? PlantaEvento::padrao();
        $layout = array_map(
            fn (array $e) => ['numero' => (int) $e['numero'], 'x' => $e['x'], 'y' => $e['y']],
            $planta['estandes'] ?? [],
        );
        $naPlanta = array_fill_keys(array_column($layout, 'numero'), true);

        $alocacoes = $edicao === null ? collect() : EstandeProjeto::where('edicao_id', $edicao->id)
            ->where('turno', $turno->value)
            ->with(['projeto:id,titulo,categoria,area_id', 'projeto.area:id,nome,sigla'])
            ->orderBy('numero')
            ->get()
            ->filter(fn (EstandeProjeto $e) => $e->projeto !== null);

        $ids = $alocacoes->pluck('projeto_id')->all();
        $credenciados = $this->comFato('credenciamentos', 'finalizado_em', $ids);
        $checados = $this->comFato('checagens_estande', 'verificado_em', $ids);
        $doProjeto = $linhas->where('situacao', '!=', 'expirada')->groupBy('projeto_id');

        $estandes = [];
        $fora = [];

        foreach ($alocacoes as $e) {
            $projeto = $e->projeto;
            $avaliacoes = $doProjeto->get($projeto->id, collect());
            $conta = fn (string $s) => $avaliacoes->where('situacao', $s)->count();

            $concluidas = $conta('concluida');
            $emAvaliacao = $conta('em_avaliacao');
            $designadas = $conta('designada');

            $linha = [
                'numero' => $e->numero,
                'projeto_id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'codigo' => $locais[$projeto->id]['codigo'] ?? null,
                'area' => $projeto->area?->nome,
                'area_sigla' => $projeto->area?->siglaDaLista(),
                'categoria' => $projeto->categoria?->label(),
                // Pronto para avaliação: passou pelo balcão e teve o estande
                // conferido — o comitê só começa ali.
                'pronto' => isset($credenciados[$projeto->id], $checados[$projeto->id]),
                'concluidas' => $concluidas,
                'em_avaliacao' => $emAvaliacao,
                'designadas' => $designadas,
                // O que manda na cor é o que está acontecendo agora.
                'situacao' => match (true) {
                    $emAvaliacao > 0 => 'em_avaliacao',
                    $concluidas > 0 => 'avaliado',
                    $designadas > 0 => 'designado',
                    default => 'pendente',
                },
                'avaliacoes' => $avaliacoes->map(fn (array $l) => [
                    'avaliacao_id' => $l['avaliacao_id'],
                    'avaliador' => $l['avaliador'],
                    'situacao' => $l['situacao'],
                    'vaga_label' => $l['vaga_label'],
                    'iniciada_em' => $l['iniciada_em'],
                    'concluida_em' => $l['concluida_em'],
                ])->values()->all(),
            ];

            if (isset($naPlanta[$e->numero])) {
                $estandes[$e->numero] = $linha;
            } else {
                $fora[] = $linha;
            }
        }

        return [
            'layout' => $layout,
            'estandes' => (object) $estandes,
            'fora_da_planta' => $fora,
        ];
    }

    /**
     * Os projetos com o fato registrado (credenciamento concluído, estande
     * checado).
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function comFato(string $tabela, string $coluna, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($tabela)
            ->whereIn('projeto_id', $ids)
            ->whereNotNull($coluna)
            ->pluck('projeto_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }
}
