<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial — **lado do admin**: designar estandes a quem confirmou
 * presença e acompanhar o que já foi avaliado.
 *
 * A designação manual **passa por cima do teto** de avaliações por projeto,
 * como em toda designação manual do portal: quem a faz sabe que está pondo mais
 * um avaliador ali. O que ela não faz é designar duas vezes a mesma pessoa para
 * o mesmo projeto — isso é erro, não decisão.
 */
class AvaliacaoPresencialAdminService
{
    /**
     * As avaliações presenciais da edição, para acompanhamento.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    public function listar(array $filtros = []): array
    {
        return AvaliacaoPresencial::query()
            ->with(['projeto:id,titulo,area_id', 'projeto.area:id,nome', 'avaliador:id,name'])
            ->when(! empty($filtros['status']), fn ($q) => $q->where('status', $filtros['status']))
            ->when(! empty($filtros['avaliador_id']), fn ($q) => $q->where('avaliador_id', $filtros['avaliador_id']))
            ->orderByDesc('id')
            ->get()
            ->map(fn (AvaliacaoPresencial $a) => [
                'id' => $a->id,
                'projeto_id' => $a->projeto_id,
                'projeto' => $a->projeto?->titulo,
                'area' => $a->projeto?->area?->nome,
                'avaliador' => $a->avaliador?->name,
                'status' => $a->status?->value,
                'status_label' => $a->status?->label(),
                'nota' => $a->nota,
                'designacao_manual' => $a->designacao_manual,
                'concluida_em' => $a->concluida_em?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Os avaliadores que confirmaram presença — os únicos que podem receber
     * estande.
     *
     * @return list<array<string, mixed>>
     */
    public function avaliadoresConfirmados(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('avaliadorProfile', fn ($q) => $q->where('presencial', true))
            ->with('avaliadorProfile.area:id,nome')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nome' => $u->name,
                'area' => $u->avaliadorProfile?->area?->nome,
                'designados' => AvaliacaoPresencial::where('avaliador_id', $u->id)->count(),
            ])
            ->all();
    }

    /**
     * Cruza projetos × avaliadores, criando as designações que faltam.
     *
     * @param  list<int>  $projetoIds
     * @param  list<int>  $avaliadorIds
     * @return array<string, mixed> o que foi criado e o que foi pulado, com o motivo
     */
    public function designar(array $projetoIds, array $avaliadorIds, User $admin): array
    {
        $lista = ListaFinal::vigente();

        if ($lista === null) {
            throw ValidationException::withMessages([
                'projeto_ids' => 'A lista final ainda não foi publicada — não há finalista para designar.',
            ]);
        }

        $finalistas = Projeto::whereIn('id', $lista->projetos()->select('projetos.id'))
            ->whereIn('id', $projetoIds)
            ->get()
            ->keyBy('id');

        $confirmados = User::whereIn('id', $avaliadorIds)
            ->whereHas('avaliadorProfile', fn ($q) => $q->where('presencial', true))
            ->get()
            ->keyBy('id');

        $criadas = 0;
        $ignoradas = [];

        DB::transaction(function () use ($finalistas, $confirmados, &$criadas, &$ignoradas) {
            foreach ($finalistas as $projeto) {
                foreach ($confirmados as $avaliador) {
                    $existe = AvaliacaoPresencial::where('projeto_id', $projeto->id)
                        ->where('avaliador_id', $avaliador->id)
                        ->exists();

                    if ($existe) {
                        $ignoradas[] = "{$projeto->titulo} → {$avaliador->name}: já está com ele.";

                        continue;
                    }

                    AvaliacaoPresencial::create([
                        'edicao_id' => Edicao::atual()?->id,
                        'projeto_id' => $projeto->id,
                        'avaliador_id' => $avaliador->id,
                        'status' => StatusAvaliacao::Designada,
                        // Designação manual: o teto por projeto não a alcança.
                        'designacao_manual' => true,
                    ]);

                    $criadas++;
                }
            }
        });

        // Quem o admin pediu e não existe no recorte: dizer é melhor do que sumir.
        foreach (array_diff($projetoIds, $finalistas->keys()->all()) as $id) {
            $ignoradas[] = "Projeto {$id}: não está na lista final.";
        }

        foreach (array_diff($avaliadorIds, $confirmados->keys()->all()) as $id) {
            $ignoradas[] = "Avaliador {$id}: não confirmou participação presencial.";
        }

        return [
            'designadas' => $criadas,
            'ignoradas' => $ignoradas,
            'resumo' => $criadas.' designação(ões) criada(s).',
        ];
    }

    /** Retira uma designação que ainda não virou avaliação. */
    public function retirar(AvaliacaoPresencial $avaliacao): void
    {
        if ($avaliacao->concluida()) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Avaliação concluída não é retirada — a nota já existe.',
            ]);
        }

        $avaliacao->delete();
    }

    /** Os finalistas, para o diálogo de designação. */
    public function finalistas(): array
    {
        $lista = ListaFinal::vigente();

        if ($lista === null) {
            return [];
        }

        $ocupadas = AvaliacaoPresencial::selectRaw('projeto_id, COUNT(*) as total')
            ->groupBy('projeto_id')
            ->pluck('total', 'projeto_id');

        return Projeto::whereIn('id', $lista->projetos()->select('projetos.id'))
            ->with('area:id,nome')
            ->orderBy('titulo')
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'area' => $p->area?->nome,
                'avaliacoes' => (int) ($ocupadas[$p->id] ?? 0),
            ])
            ->all();
    }
}
