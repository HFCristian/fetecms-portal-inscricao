<?php

namespace App\Services;

use App\Enums\Turno;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\User;
use App\Support\JanelaTurnos;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial → **turnos** (Sprint 169).
 *
 * A agenda que a distribuição presencial precisa antes de existir: o horário de
 * cada turno, que vale em todos os dias do evento ({@see JanelaTurnos}). É dela
 * que sai o prazo de cada avaliação — o fim do turno mais 30 minutos —, e é
 * nela que o avaliador avalia.
 *
 * Até a Sprint 221 o avaliador também precisava ser **ativado** num turno, na
 * cabine da avaliação. A cabine saiu: quem confirmou o presencial avalia no
 * turno em andamento, escolhendo o estande no mapa.
 *
 * O modo de teste (conta demo) separa os dois mundos como no resto do dia do
 * evento: a lista final de demonstração e só os avaliadores demo.
 */
class TurnosPresenciaisService
{
    /**
     * A configuração da tela: números, agenda e a ocorrência em foco. Os
     * horários se editam em Parametrização → Datas e períodos ({@see agenda()}).
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
            'fila_avaliador' => $janela->fila(),
            'por_projeto' => $janela->porProjeto(),
            'comuns_por_projeto' => $janela->comunsPorProjeto(),
            'comite_por_projeto' => $janela->comitePorProjeto(),
            'peso_comite' => $janela->pesoComite(),
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
     * Grava a fila de cada avaliador e as vagas por projeto —
     * comuns, da comissão especial e o peso da comissão (Sprint 222). Campo em
     * branco volta ao padrão; zero numa das vagas é resposta, mas o projeto
     * precisa de ao menos uma.
     *
     * @param  array<string, mixed>  $dados
     */
    public function salvarConfig(array $dados): void
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages(['fila_avaliador' => 'Nenhuma edição em vigor.']);
        }

        $comuns = $dados['comuns_por_projeto'] ?? null;
        $comite = $dados['comite_por_projeto'] ?? null;

        if ((int) ($comuns ?? JanelaTurnos::PADRAO_COMUNS) + (int) ($comite ?? JanelaTurnos::PADRAO_COMITE) < 1) {
            throw ValidationException::withMessages([
                'comuns_por_projeto' => 'Cada projeto precisa de ao menos uma avaliação presencial.',
            ]);
        }

        $edicao->update([
            'presencial_fila_avaliador' => $dados['fila_avaliador'] ?? null,
            'presencial_comuns_por_projeto' => $comuns,
            'presencial_comite_por_projeto' => $comite,
            'presencial_peso_comite' => $dados['peso_comite'] ?? null,
        ]);
    }

    /**
     * A agenda da avaliação presencial como Parametrização → Datas e períodos a
     * edita (Sprint 224): cada dia do evento, cada turno com o horário dele e o
     * da avaliação, e as ocorrências que isso forma.
     *
     * @return array<string, mixed>
     */
    public function agenda(): array
    {
        $edicao = Edicao::atual();
        $janela = JanelaTurnos::daEdicao($edicao);

        return [
            'dias' => array_map(fn (string $dia) => [
                'dia' => $dia,
                'label' => CarbonImmutable::parse($dia)->locale('pt_BR')->translatedFormat('D, d/m'),
            ], $janela->dias()),
            'agenda' => (object) $janela->agenda(),
            'turnos' => Turno::opcoes(),
            'margem_padrao' => JanelaTurnos::MARGEM_MINUTOS,
            'configurada' => $janela->configurada(),
            'evento_de_label' => $edicao?->evento_de?->format('d/m/Y'),
            'evento_ate_label' => $edicao?->evento_ate?->format('d/m/Y'),
            'ocorrencias' => $janela->ocorrencias(),
        ];
    }

    /**
     * Grava a agenda inteira (substitui a anterior). Turno sem horário num dia
     * não acontece naquele dia.
     *
     * @param  array<string, mixed>  $agenda
     */
    public function salvarAgenda(array $agenda): void
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages(['agenda' => 'Nenhuma edição em vigor.']);
        }

        $janela = JanelaTurnos::daEdicao($edicao);

        if ($janela->dias() === []) {
            throw ValidationException::withMessages([
                'agenda' => 'Defina o período do evento antes dos horários dos turnos.',
            ]);
        }

        $edicao->update(['agenda_presencial' => JanelaTurnos::validar($agenda, $janela->dias()) ?: null]);
    }

    /**
     * Quem confirmou que avalia presencialmente, com o que já tem na ocorrência
     * em foco.
     *
     * @param  array<string, mixed>|null  $foco
     * @return list<array<string, mixed>>
     */
    public function avaliadores(?array $foco, bool $demo, ?string $busca = null): array
    {
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
        $liberados = array_flip(app(LiberacaoPresencialService::class)->idsLiberados());

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

        return $avaliadores->map(fn (User $u) => [
            'id' => $u->id,
            'nome' => $u->name,
            'email' => $u->email,
            'area' => $u->avaliadorProfile?->area?->nome,
            'subarea' => $u->avaliadorProfile?->subarea?->nome,
            'comissao' => (bool) $u->avaliadorProfile?->comissao_especial,
            'liberado' => isset($liberados[$u->id]),
            'no_turno' => (int) ($noFoco[$u->id] ?? 0),
            'concluidas' => (int) ($concluidas[$u->id] ?? 0),
        ])->all();
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
