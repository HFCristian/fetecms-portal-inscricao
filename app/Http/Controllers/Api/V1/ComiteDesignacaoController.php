<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DesignacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /** Projetos submetidos e os avaliadores da comissão especial. */
    public function opcoes(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'projeto' => ['nullable', 'string', 'max:120'],
            'avaliador' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->designacao->opcoesDeDesignacao(
                (string) ($filtros['projeto'] ?? ''),
                (string) ($filtros['avaliador'] ?? ''),
                soComissao: true,
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
