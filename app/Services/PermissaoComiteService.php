<?php

namespace App\Services;

use App\Enums\StatusPedidoPermissao;
use App\Enums\TipoRegistro;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\PedidoAvaliacaoComite;
use App\Models\Projeto;
use App\Models\User;
use App\Support\JanelaTurnos;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * O comitê especial avalia **só o que foi designado a ele** (Sprint 234).
 *
 * Com a regra ligada na edição (Parametrização → Avaliação presencial → Comitê
 * especial), o avaliador da comissão só inicia no estande o projeto que é
 * dele por um destes caminhos:
 *
 * - uma **designação no estande** (distribuição presencial ou à mão) — vale
 *   inclusive a que venceu sem envio, que continua sendo dele;
 * - uma **designação do comitê** (Comitê especial → Designações,
 *   `avaliacoes.designada_pelo_comite`);
 * - um **pedido de permissão aprovado** pela organização.
 *
 * Fora disso ele pede permissão, e a organização decide em Comitê especial →
 * Designações, logo abaixo da designação. A regra não mexe em quem não é da
 * comissão, nem nas vagas: com a permissão, o início segue as travas de sempre
 * (turno, credenciamento, checagem, vaga).
 */
class PermissaoComiteService
{
    public function __construct(
        private readonly LocaisFinalistasService $locais,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /** A regra está ligada na edição em escopo? */
    public function ativa(): bool
    {
        return Edicao::comiteSoDesignados();
    }

    /** A regra vale para este avaliador (ligada e ele é da comissão)? */
    public function seAplica(User $avaliador): bool
    {
        return $this->ativa() && DistribuicaoPresencialService::daComissao($avaliador);
    }

    /**
     * Os projetos que o avaliador pode iniciar com a regra ligada.
     *
     * @return array<int, true>
     */
    public function liberados(User $avaliador): array
    {
        $edicaoId = Edicao::atual()?->id;

        $ids = AvaliacaoPresencial::where('avaliador_id', $avaliador->id)
            ->where('edicao_id', $edicaoId)
            ->pluck('projeto_id')
            ->concat(Avaliacao::where('avaliador_id', $avaliador->id)
                ->where('designada_pelo_comite', true)
                ->whereHas('projeto')
                ->pluck('projeto_id'))
            ->concat(PedidoAvaliacaoComite::where('avaliador_id', $avaliador->id)
                ->where('edicao_id', $edicaoId)
                ->where('status', StatusPedidoPermissao::Aprovado->value)
                ->pluck('projeto_id'));

        return $ids->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * Os pedidos do avaliador na edição, por projeto.
     *
     * @return Collection<int, PedidoAvaliacaoComite>
     */
    public function pedidosDe(User $avaliador): Collection
    {
        return PedidoAvaliacaoComite::where('avaliador_id', $avaliador->id)
            ->where('edicao_id', Edicao::atual()?->id)
            ->with('projeto:id,titulo')
            ->orderByDesc('updated_at')
            ->get()
            ->keyBy('projeto_id');
    }

    /**
     * O que o avaliador vê ao lado de um projeto que ele não pode iniciar:
     * `necessaria` (ainda não pediu), `pendente` ou `recusada`. Nulo quando
     * pode iniciar — a regra está desligada, não é com ele, ou o projeto é dele.
     *
     * @param  array<int, true>  $liberados
     * @param  Collection<int, PedidoAvaliacaoComite>  $pedidos
     */
    public function situacao(int $projetoId, array $liberados, Collection $pedidos): ?string
    {
        if (isset($liberados[$projetoId])) {
            return null;
        }

        return match ($pedidos->get($projetoId)?->status) {
            StatusPedidoPermissao::Pendente => 'pendente',
            StatusPedidoPermissao::Recusado => 'recusada',
            default => 'necessaria',
        };
    }

    /** Barra o início fora das designações, com a regra ligada. */
    public function garantir(User $avaliador, Projeto $projeto): void
    {
        if (! $this->seAplica($avaliador) || isset($this->liberados($avaliador)[$projeto->id])) {
            return;
        }

        $pendente = $this->pedidosDe($avaliador)->get($projeto->id)?->status === StatusPedidoPermissao::Pendente;

        throw ValidationException::withMessages([
            'permissao' => $pendente
                ? 'Seu pedido de permissão para avaliar este projeto ainda aguarda a organização.'
                : 'O comitê avalia só os projetos designados. Peça permissão à organização para avaliar este.',
        ]);
    }

    /** O avaliador pede para avaliar um projeto fora das designações dele. */
    public function solicitar(User $avaliador, Projeto $projeto, ?string $justificativa): PedidoAvaliacaoComite
    {
        if (! $this->seAplica($avaliador)) {
            throw ValidationException::withMessages([
                'permissao' => 'Não é preciso pedir permissão: você pode avaliar este projeto direto.',
            ]);
        }

        if (isset($this->liberados($avaliador)[$projeto->id])) {
            throw ValidationException::withMessages([
                'permissao' => 'Este projeto já é seu: pode iniciar a avaliação.',
            ]);
        }

        $pedido = PedidoAvaliacaoComite::firstOrNew([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
        ]);

        if ($pedido->exists && $pedido->status === StatusPedidoPermissao::Pendente) {
            throw ValidationException::withMessages([
                'permissao' => 'Você já pediu: o pedido aguarda a organização.',
            ]);
        }

        // Pedir de novo depois de uma recusa reabre o mesmo pedido.
        $pedido->fill([
            'status' => StatusPedidoPermissao::Pendente,
            'justificativa' => $this->texto($justificativa),
            'resposta' => null,
            'decidido_por' => null,
            'decidido_em' => null,
        ])->save();

        return $pedido;
    }

    /**
     * Os pedidos da edição para a tela da organização: pendentes primeiro (o
     * mais antigo no topo, que é quem espera há mais tempo), depois os
     * decididos, do mais recente.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(?string $situacao = null): array
    {
        $locais = $this->locais->todos();

        $pedidos = PedidoAvaliacaoComite::where('edicao_id', Edicao::atual()?->id)
            ->when($situacao === 'pendente', fn ($q) => $q->where('status', StatusPedidoPermissao::Pendente->value))
            ->when($situacao === 'decididos', fn ($q) => $q->where('status', '!=', StatusPedidoPermissao::Pendente->value))
            ->whereHas('projeto')
            ->with(['projeto:id,titulo,area_id,categoria', 'projeto.area:id,nome', 'avaliador:id,name,email', 'decisor:id,name'])
            ->get();

        [$pendentes, $decididos] = $pedidos->partition(fn (PedidoAvaliacaoComite $p) => $p->status === StatusPedidoPermissao::Pendente);

        return $pendentes->sortBy('updated_at')
            ->concat($decididos->sortByDesc('decidido_em'))
            ->map(fn (PedidoAvaliacaoComite $p) => $this->linha($p, $locais))
            ->values()
            ->all();
    }

    /** Quantos pedidos aguardam a organização. */
    public function pendentes(): int
    {
        return PedidoAvaliacaoComite::where('edicao_id', Edicao::atual()?->id)
            ->where('status', StatusPedidoPermissao::Pendente->value)
            ->whereHas('projeto')
            ->count();
    }

    /**
     * A organização aprova ou recusa. Decidir de novo é permitido (a
     * organização muda de ideia), e o nome de quem decidiu por último fica.
     *
     * @return array<string, mixed>
     */
    public function decidir(PedidoAvaliacaoComite $pedido, bool $aprovar, ?string $resposta, User $admin): array
    {
        abort_unless($pedido->edicao_id === Edicao::atual()?->id, 404);

        $pedido->update([
            'status' => $aprovar ? StatusPedidoPermissao::Aprovado : StatusPedidoPermissao::Recusado,
            'resposta' => $this->texto($resposta),
            'decidido_por' => $admin->id,
            'decidido_em' => now(),
        ]);

        return $this->linha($pedido->fresh(['projeto.area', 'avaliador', 'decisor']), $this->locais->todos());
    }

    /**
     * O lançamento noturno do comitê (Sprint 238): as horas, o rótulo e se
     * está ligado (precisa também do período do evento).
     *
     * @return array<string, mixed>
     */
    public function noite(): array
    {
        $janela = JanelaTurnos::daEdicao();

        return [
            ...$janela->noite(),
            'rotulo' => $janela->noiteRotulo(),
            'ligado' => $janela->noiteConfigurada(),
            'sem_evento' => $janela->dias() === [],
        ];
    }

    /** Grava o lançamento noturno (em branco desliga), com o registro do "de → para". */
    public function definirNoite(?string $inicio, ?string $fim, User $admin): void
    {
        $edicao = Edicao::atual();
        abort_if($edicao === null, 422, 'Nenhuma edição em curso.');

        [$inicio, $fim] = JanelaTurnos::validarNoite($inicio, $fim);
        $anterior = JanelaTurnos::daEdicao($edicao)->noiteRotulo();

        $edicao->update(['presencial_comite_noite_inicio' => $inicio, 'presencial_comite_noite_fim' => $fim]);
        $novo = JanelaTurnos::daEdicao($edicao->fresh())->noiteRotulo();

        if ($anterior !== $novo) {
            $this->registros->parametroAvaliacao(
                TipoRegistro::PresencialComiteLancamentoNoturno,
                $admin,
                $anterior ?? 'desligado',
                $novo ?? 'desligado',
            );
        }
    }

    /** Liga ou desliga a regra na edição em escopo, com o registro. */
    public function definir(bool $ativa, User $admin): void
    {
        $anterior = $this->ativa();
        Edicao::atual()?->update(['presencial_comite_so_designados' => $ativa]);

        if ($anterior !== $ativa) {
            $this->registros->parametroAvaliacao(
                TipoRegistro::PresencialComiteSoDesignados,
                $admin,
                $anterior ? 'ligada' : 'desligada',
                $ativa ? 'ligada' : 'desligada',
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $locais
     * @return array<string, mixed>
     */
    private function linha(PedidoAvaliacaoComite $p, array $locais): array
    {
        $local = $locais[$p->projeto_id] ?? [];

        return [
            'id' => $p->id,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'justificativa' => $p->justificativa,
            'resposta' => $p->resposta,
            'pedido_em' => $p->updated_at?->toIso8601String(),
            'decidido_em' => $p->decidido_em?->toIso8601String(),
            'decidido_por' => $p->decisor?->name,
            'avaliador' => ['id' => $p->avaliador_id, 'nome' => $p->avaliador?->name, 'email' => $p->avaliador?->email],
            'projeto' => [
                'id' => $p->projeto_id,
                'titulo' => $p->projeto?->titulo,
                'area' => $p->projeto?->area?->nome,
                'categoria' => $p->projeto?->categoria?->label(),
                'codigo' => $local['codigo'] ?? null,
                'estande' => $local['estande'] ?? null,
                'turno_label' => $local['turno_label'] ?? null,
            ],
        ];
    }

    private function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, 500);
    }
}
