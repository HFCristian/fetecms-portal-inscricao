<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Turno;
use App\Http\Controllers\Controller;
use App\Services\DesignacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Designação **do comitê especial** (aba Comitê).
 *
 * Quem administra o comitê designa projetos **só** para os avaliadores da
 * comissão especial. É outra tela, e não um filtro na de Avaliação online, por
 * duas razões: quem cuida do comitê costuma ter **apenas** essa aba no escopo,
 * e mesmo quem tem as duas está fazendo coisas diferentes em cada uma — aqui a
 * pergunta é "quem do comitê vê este projeto?", não "como cobrir a feira".
 *
 * A restrição é do **servidor**, não da tela: trocar o id no payload não
 * alcança um avaliador de fora da comissão.
 */
class ComiteDesignacaoController extends Controller
{
    public function __construct(private readonly DesignacaoService $designacao) {}

    /**
     * Projetos submetidos e os avaliadores da comissão especial. A busca de
     * projetos casa também com o código da lista final, o estande e o turno, e
     * os três viram filtros próprios.
     */
    public function opcoes(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'projeto' => ['nullable', 'string', 'max:120'],
            'avaliador' => ['nullable', 'string', 'max:120'],
            'codigo' => ['nullable', 'string', 'max:40'],
            // Como na planta: "042" é o estande 42.
            'estande' => ['nullable', 'regex:/^\d{1,4}$/'],
            'turno' => ['nullable', Rule::in(Turno::valores())],
            // "Mostrar mais" de cada lista: a tela pede de 30 em 30.
            'limite_projetos' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'limite_avaliadores' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], [
            'estande.regex' => 'Informe o número do estande.',
            'turno.in' => 'Turno inválido.',
        ]);

        return response()->json([
            'data' => $this->designacao->opcoesDeDesignacao(
                (string) ($filtros['projeto'] ?? ''),
                (string) ($filtros['avaliador'] ?? ''),
                limite: (int) ($filtros['limite_projetos'] ?? 30),
                soComissao: true,
                local: [
                    'codigo' => $filtros['codigo'] ?? null,
                    'estande' => $filtros['estande'] ?? null,
                    'turno' => $filtros['turno'] ?? null,
                ],
                limiteAvaliadores: (int) ($filtros['limite_avaliadores'] ?? 30),
            ),
        ]);
    }

    /** Cruza os projetos marcados com os avaliadores do comitê marcados. */
    public function designar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'projeto_ids' => ['required', 'array', 'min:1', 'max:200'],
            'projeto_ids.*' => ['integer'],
            'avaliador_ids' => ['required', 'array', 'min:1', 'max:200'],
            'avaliador_ids.*' => ['integer'],
        ]);

        $resultado = $this->designacao->designar(
            $dados['projeto_ids'],
            $dados['avaliador_ids'],
            $request->user(),
            soComissao: true,
        );

        return response()->json([
            'data' => $resultado,
            'meta' => ['message' => trim(($resultado['resumo'] ?? '').' '.($resultado['problemas'] ?? ''))],
        ]);
    }
}
