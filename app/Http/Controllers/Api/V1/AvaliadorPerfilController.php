<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Avaliador\AtualizarClassificacaoRequest;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Rules\CidadeDoEstado;
use App\Services\AvaliadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perfil do avaliador: os números que ele acumulou na feira (avaliações
 * concluídas, carga horária do certificado e posição no ranking de quem mais
 * avaliou) e a troca da própria área/subárea, permitida só antes de o período
 * de avaliação começar.
 */
class AvaliadorPerfilController extends Controller
{
    public function __construct(private readonly AvaliadorService $avaliadores) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('avaliadorProfile.area', 'avaliadorProfile.subarea', 'avaliadorProfile.estado', 'avaliadorProfile.cidade');

        return response()->json(['data' => $this->perfil($user)]);
    }

    /** Troca a área (e a subárea opcional) — 422 se a avaliação já foi liberada. */
    public function atualizarClassificacao(AtualizarClassificacaoRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->avaliadores->atualizarClassificacao($user, $request->validated());

        return response()->json([
            'data' => $this->perfil($user->fresh(['avaliadorProfile.area', 'avaliadorProfile.subarea'])),
            'meta' => ['message' => 'Área de atuação atualizada.'],
        ]);
    }

    /**
     * Atualiza de onde o avaliador é. Diferente da área, pode ser trocado a
     * qualquer momento — a localidade não entra na distribuição.
     */
    public function atualizarLocalidade(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'estado_id' => ['nullable', 'integer', 'exists:estados,id'],
            'cidade_id' => ['nullable', 'integer', 'exists:cidades,id', new CidadeDoEstado($request->input('estado_id'))],
        ]);

        $user = $request->user();
        $this->avaliadores->atualizarLocalidade($user, $dados);

        return response()->json([
            'data' => $this->perfil($user->fresh([
                'avaliadorProfile.area', 'avaliadorProfile.subarea',
                'avaliadorProfile.estado', 'avaliadorProfile.cidade',
            ])),
            'meta' => ['message' => 'Localidade atualizada.'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function perfil($user): array
    {
        $perfil = $user->avaliadorProfile;
        $edicao = Edicao::atual();

        return [
            'nome' => $user->name,
            'email' => $user->email,
            'titulacao' => $perfil?->titulacao,
            'area_id' => $perfil?->area_id,
            'area' => $perfil?->area?->nome,
            'subarea_id' => $perfil?->subarea_id,
            'subarea' => $perfil?->subarea?->nome,
            'estado_id' => $perfil?->estado_id,
            'estado' => $perfil?->estado?->nome,
            'cidade_id' => $perfil?->cidade_id,
            'cidade' => $perfil?->cidade?->nome,
            'estatisticas' => $this->avaliadores->estatisticas($user),
            // Fora do período de avaliação a área ainda pode ser trocada.
            'pode_trocar_area' => $this->avaliadores->podeTrocarClassificacao(),
            'liberada_em_label' => $edicao?->avaliacao_liberada_em?->format('d/m/Y H:i'),
            // Trocar de área não remexe nas designações que o admin já fez.
            'projetos_designados' => $user->avaliacoes()->count(),
            'minutos_por_avaliacao' => AvaliadorProfile::MINUTOS_POR_AVALIACAO,
        ];
    }
}
