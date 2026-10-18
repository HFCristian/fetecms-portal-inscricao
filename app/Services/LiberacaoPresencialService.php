<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\AvaliadorLiberacaoPresencial;
use App\Models\Edicao;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Avaliação presencial → **Validação de avaliadores** (Sprint 223).
 *
 * Confirmar o presencial — o avaliador online que disse "sim", ou a conta que
 * a organização criou em Cadastro de avaliadores — não basta para avaliar: a
 * organização **libera** cada um, e só então ele começa avaliação no estande e
 * entra na distribuição. A liberação vale para o **evento inteiro** da edição
 * (a cabine por turno saiu na Sprint 221 e não voltou). Revogar tira a
 * liberação e devolve o que ainda estava só designado para ele.
 */
class LiberacaoPresencialService
{
    /** O avaliador está liberado na edição em escopo? */
    public function liberado(User $avaliador): bool
    {
        return AvaliadorLiberacaoPresencial::where('edicao_id', Edicao::atual()?->id)
            ->where('user_id', $avaliador->id)
            ->exists();
    }

    /**
     * Os ids liberados na edição em escopo.
     *
     * @return list<int>
     */
    public function idsLiberados(): array
    {
        return AvaliadorLiberacaoPresencial::where('edicao_id', Edicao::atual()?->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Quem confirmou o presencial, com a situação de cada um — e os números do
     * topo da tela.
     *
     * @param  array{busca?: string|null, situacao?: string|null}  $filtros
     * @return array<string, mixed>
     */
    public function listar(array $filtros, bool $demo): array
    {
        $busca = Str::lower(trim((string) ($filtros['busca'] ?? '')));
        $situacao = $filtros['situacao'] ?? null;
        $liberacoes = AvaliadorLiberacaoPresencial::where('edicao_id', Edicao::atual()?->id)
            ->with('liberadoPor:id,name')
            ->get()
            ->keyBy('user_id');

        $todos = User::query()
            ->where('is_active', true)
            ->where('is_demo', $demo)
            ->whereHas('avaliadorProfile', fn ($q) => $q->where('presencial', true))
            ->with(['avaliadorProfile.area:id,nome', 'avaliadorProfile.subarea:id,nome'])
            ->withCount('avaliacoesPresenciais')
            ->orderBy('name')
            ->get();

        $linhas = $todos
            ->filter(fn (User $u) => $busca === ''
                || str_contains(Str::lower($u->name), $busca)
                || str_contains(Str::lower($u->email), $busca))
            ->filter(fn (User $u) => match ($situacao) {
                'liberados' => $liberacoes->has($u->id),
                'aguardando' => ! $liberacoes->has($u->id),
                default => true,
            })
            ->map(function (User $u) use ($liberacoes) {
                $liberacao = $liberacoes[$u->id] ?? null;
                $perfil = $u->avaliadorProfile;

                return [
                    'id' => $u->id,
                    'nome' => $u->name,
                    'email' => $u->email,
                    'area' => $perfil?->area?->nome,
                    'subarea' => $perfil?->subarea?->nome,
                    'apenas_presencial' => (bool) $perfil?->apenas_presencial,
                    'comissao' => (bool) $perfil?->comissao_especial,
                    'avaliacoes' => (int) $u->avaliacoes_presenciais_count,
                    'liberado' => $liberacao !== null,
                    'liberado_por' => $liberacao?->liberadoPor?->name,
                    'liberado_em' => $liberacao?->created_at?->toIso8601String(),
                    'liberado_em_label' => $liberacao?->created_at?->format('d/m/Y H:i'),
                ];
            })
            ->values()
            ->all();

        $liberados = $todos->filter(fn (User $u) => $liberacoes->has($u->id))->count();

        return [
            'resumo' => [
                'total' => $todos->count(),
                'liberados' => $liberados,
                'aguardando' => $todos->count() - $liberados,
            ],
            'avaliadores' => $linhas,
        ];
    }

    /**
     * Libera os avaliadores pedidos. Só alcança quem confirmou o presencial
     * (no mundo do modo de teste: demo com demo, real com real); quem já está
     * liberado fica como estava.
     *
     * @param  list<int>  $ids
     * @return array{liberados: int, ignorados: list<string>}
     */
    public function liberar(array $ids, User $admin, bool $demo): array
    {
        $edicaoId = Edicao::atual()?->id;
        $confirmados = User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->where('is_demo', $demo)
            ->whereHas('avaliadorProfile', fn ($q) => $q->where('presencial', true))
            ->pluck('name', 'id');
        $ja = AvaliadorLiberacaoPresencial::where('edicao_id', $edicaoId)->whereIn('user_id', $ids)->pluck('user_id')->all();

        $ignorados = [];
        foreach (array_unique($ids) as $id) {
            if (! $confirmados->has($id)) {
                $ignorados[] = (User::find($id)?->name ?? "Avaliador {$id}").': não confirmou a avaliação presencial.';
            }
        }

        $novos = $confirmados->keys()->diff($ja)->values();

        DB::transaction(function () use ($novos, $edicaoId, $admin) {
            foreach ($novos as $id) {
                AvaliadorLiberacaoPresencial::create([
                    'edicao_id' => $edicaoId,
                    'user_id' => $id,
                    'liberado_por' => $admin->id,
                ]);
            }
        });

        return ['liberados' => $novos->count(), 'ignorados' => $ignorados];
    }

    /**
     * Revoga a liberação. O que estava só designado para ele (sem abrir) volta
     * ao bolo, para outro avaliador receber; o que ele já abriu fica, em
     * leitura — a organização retira em Distribuição presencial se quiser.
     *
     * @return array{devolvidas: int}
     */
    public function revogar(User $avaliador): array
    {
        $edicaoId = Edicao::atual()?->id;

        return DB::transaction(function () use ($avaliador, $edicaoId) {
            AvaliadorLiberacaoPresencial::where('edicao_id', $edicaoId)->where('user_id', $avaliador->id)->delete();

            $devolvidas = AvaliacaoPresencial::where('edicao_id', $edicaoId)
                ->where('avaliador_id', $avaliador->id)
                ->where('status', StatusAvaliacao::Designada->value)
                ->delete();

            return ['devolvidas' => $devolvidas];
        });
    }
}
