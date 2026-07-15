<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Avaliacao;
use App\Models\Edicao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação online — lado do avaliador. Antes da data de liberação (definida pelo
 * admin na edição atual) nada aparece; depois, o avaliador vê os projetos que lhe
 * foram designados. A atribuição de nota virá em sprint futura.
 */
class AvaliadorAvaliacaoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $edicao = Edicao::atual();
        $liberada = (bool) $edicao?->avaliacaoLiberada();

        $projetos = [];
        if ($liberada) {
            $projetos = Avaliacao::query()
                ->where('avaliador_id', $request->user()->id)
                ->with(['projeto:id,titulo,area_id', 'projeto.area:id,nome'])
                ->get()
                ->map(fn (Avaliacao $a) => [
                    'avaliacao_id' => $a->id,
                    'projeto_id' => $a->projeto_id,
                    'titulo' => $a->projeto?->titulo,
                    'area' => $a->projeto?->area?->nome,
                    'status' => $a->status->value,
                    'status_label' => $a->status->label(),
                    'nota' => $a->nota,
                ])->all();
        }

        return response()->json(['data' => [
            'liberada' => $liberada,
            'liberada_em' => $edicao?->avaliacao_liberada_em?->toIso8601String(),
            'projetos' => $projetos,
        ]]);
    }
}
