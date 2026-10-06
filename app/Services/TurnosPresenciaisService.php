<?php

namespace App\Services;

use App\Enums\Turno;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorTurnoPresencial;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\User;
use App\Support\JanelaTurnos;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial → **turnos e ativação** (Sprint 169).
 *
 * Duas coisas que a distribuição presencial precisa antes de existir:
 *
 * - **a agenda**: o horário de cada turno, que vale em todos os dias do evento
 *   ({@see JanelaTurnos}). É dela que sai o prazo de cada avaliação — o fim do
 *   turno mais 30 minutos;
 * - **quem está de plantão**: o avaliador que disse "sim" no cadastro só avalia
 *   depois de passar na cabine da avaliação, onde a organização o **ativa**
 *   para o turno em curso — ou pré-ativa os seguintes que ele escolher. A
 *   promessa feita semanas antes não diz quem de fato veio, e distribuir para
 *   quem não veio deixa estande sem visita.
 *
 * O modo de teste (conta demo) separa os dois mundos como no resto do dia do
 * evento: a lista final de demonstração e só os avaliadores demo.
 */
class TurnosPresenciaisService
{
    /**
     * A configuração da tela: horários, números, agenda e a ocorrência em foco.
     *
     * @return array<string, mixed>
     */
    public function config(?User $admin, bool $teste = false, ?string $dia = null, ?string $turno = null): array
    {
        $edicao = Edicao::atual();
        $janela = JanelaTurnos::daEdicao($edicao);
        $demo = $this->emTeste($admin, $teste);
        $foco = $this->ocorrenciaPedida($janela, $dia, $turno) ?? $janela->emFoco();

        return [
            'horarios' => (object) $janela->horarios(),
            'fila_avaliador' => $janela->fila(),
            'por_projeto' => $janela->porProjeto(),
            'margem_minutos' => JanelaTurnos::MARGEM_MINUTOS,
            'configurada' => $janela->configurada(),
            'evento_de_label' => $edicao?->evento_de?->format('d/m/Y'),
            'evento_ate_label' => $edicao?->evento_ate?->format('d/m/Y'),
            'ocorrencias' => $janela->ocorrencias(),
            'foco' => $foco,
            'turnos' => Turno::opcoes(),
            'lista' => $this->resumoLista(ListaFinal::vigente($edicao, $demo)),
            'modo_teste' => $demo,
            'is_demo' => (bool) $admin?->is_demo,
        ];
    }

    /**
     * Grava os horários e os dois números da distribuição.
     *
     * @param  array<string, mixed>  $dados
     */
    public function salvarConfig(array $dados): void
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages(['horarios' => 'Nenhuma edição em vigor.']);
        }

        $edicao->update([
            'horarios_turnos' => JanelaTurnos::validar($dados['horarios'] ?? []) ?: null,
            'presencial_fila_avaliador' => $dados['fila_avaliador'] ?? null,
            'presencial_por_projeto' => $dados['por_projeto'] ?? null,
        ]);
    }

    /**
     * Quem confirmou que avalia presencialmente, com os turnos em que está
     * ativado e o que já tem na ocorrência em foco.
     *
     * @param  array<string, mixed>|null  $foco
     * @return list<array<string, mixed>>
     */
    public function avaliadores(?array $foco, bool $demo, ?string $busca = null): array
    {
        $edicao = Edicao::atual();
        $busca = trim((string) $busca);

        $avaliadores = User::query()
            ->where('is_active', true)
            ->where('is_demo', $demo)
            ->whereHas('avaliadorProfile', fn ($q) => $q->where('presencial', true))
            ->when($busca !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('name', 'like', "%{$busca}%")
                ->orWhere('email', 'like', "%{$busca}%")))
            ->with(['avaliadorProfile.area:id,nome', 'avaliadorProfile.subarea:id,nome'])
            ->orderBy('name')
            ->get();

        $ids = $avaliadores->pluck('id');
        $ativacoes = AvaliadorTurnoPresencial::where('edicao_id', $edicao?->id)
            ->whereIn('user_id', $ids)
            ->get()
            ->groupBy('user_id');

        $noFoco = $foco === null ? collect() : AvaliacaoPresencial::whereIn('avaliador_id', $ids)
            ->where('dia', $foco['dia'])
            ->where('turno', $foco['turno'])
            ->selectRaw('avaliador_id, COUNT(*) as total')
            ->groupBy('avaliador_id')
            ->pluck('total', 'avaliador_id');

        $concluidas = AvaliacaoPresencial::whereIn('avaliador_id', $ids)
            ->where('status', 'concluida')
            ->selectRaw('avaliador_id, COUNT(*) as total')
            ->groupBy('avaliador_id')
            ->pluck('total', 'avaliador_id');

        return $avaliadores->map(function (User $u) use ($ativacoes, $foco, $noFoco, $concluidas) {
            $turnos = ($ativacoes[$u->id] ?? collect())->map(fn (AvaliadorTurnoPresencial $a) => $a->chave())->values()->all();

            return [
                'id' => $u->id,
                'nome' => $u->name,
                'email' => $u->email,
                'area' => $u->avaliadorProfile?->area?->nome,
                'subarea' => $u->avaliadorProfile?->subarea?->nome,
                'turnos' => $turnos,
                'ativo' => $foco !== null && in_array($foco['chave'], $turnos, true),
                'no_turno' => (int) ($noFoco[$u->id] ?? 0),
                'concluidas' => (int) ($concluidas[$u->id] ?? 0),
            ];
        })->all();
    }

    /**
     * Substitui os turnos em que o avaliador está ativado.
     *
     * @param  list<string>  $chaves  "2026-10-20|A"
     */
    public function definirTurnos(User $avaliador, array $chaves, User $admin): void
    {
        if (! $avaliador->isAvaliador() || $avaliador->avaliadorProfile()->first()?->presencial !== true) {
            throw ValidationException::withMessages([
                'avaliador' => 'Só quem confirmou a avaliação presencial pode ser ativado num turno.',
            ]);
        }

        $edicao = Edicao::atual();
        $janela = JanelaTurnos::daEdicao($edicao);
        $pedidas = [];

        foreach (array_unique($chaves) as $chave) {
            [$dia, $valor] = array_pad(explode('|', (string) $chave, 2), 2, null);
            $turno = Turno::tryFrom((string) $valor);

            if ($turno === null || ! $janela->existe((string) $dia, $turno)) {
                throw ValidationException::withMessages([
                    'turnos' => 'Turno fora da agenda do evento: confira as datas do evento e o horário dos turnos.',
                ]);
            }

            $pedidas[$dia.'|'.$turno->value] = [$dia, $turno];
        }

        DB::transaction(function () use ($avaliador, $edicao, $pedidas, $admin) {
            $atuais = AvaliadorTurnoPresencial::where('edicao_id', $edicao->id)
                ->where('user_id', $avaliador->id)
                ->get()
                ->keyBy(fn (AvaliadorTurnoPresencial $a) => $a->chave());

            foreach ($atuais as $chave => $ativacao) {
                if (! isset($pedidas[$chave])) {
                    $ativacao->delete();
                }
            }

            foreach ($pedidas as $chave => [$dia, $turno]) {
                if (! $atuais->has($chave)) {
                    AvaliadorTurnoPresencial::create([
                        'edicao_id' => $edicao->id,
                        'user_id' => $avaliador->id,
                        'dia' => $dia,
                        'turno' => $turno,
                        'ativado_por' => $admin->id,
                    ]);
                }
            }
        });
    }

    /** O avaliador está ativado nesta ocorrência? */
    public function ativado(User $avaliador, string $dia, Turno $turno): bool
    {
        return AvaliadorTurnoPresencial::where('edicao_id', Edicao::atual()?->id)
            ->where('user_id', $avaliador->id)
            ->whereDate('dia', $dia)
            ->where('turno', $turno->value)
            ->exists();
    }

    /** Só a conta demo tem modo de teste. */
    public function emTeste(?User $user, bool $teste): bool
    {
        return $teste && (bool) $user?->is_demo;
    }

    /** @return array<string, mixed>|null a ocorrência pedida, se ela existe na agenda */
    private function ocorrenciaPedida(JanelaTurnos $janela, ?string $dia, ?string $turno): ?array
    {
        $turno = Turno::tryFrom((string) $turno);

        return $dia !== null && $turno !== null && $janela->existe($dia, $turno)
            ? $janela->ocorrencia($dia, $turno)
            : null;
    }

    /** @return array<string, mixed>|null */
    private function resumoLista(?ListaFinal $lista): ?array
    {
        return $lista === null ? null : [
            'id' => $lista->id,
            'nome' => $lista->nome,
            'versao' => (int) $lista->versao,
            'demo' => (bool) $lista->demo,
        ];
    }
}
