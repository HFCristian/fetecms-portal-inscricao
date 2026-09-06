<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O avaliador tentou abrir um projeto que já reuniu as avaliações que a
 * categoria dele pede (concluídas + em andamento).
 *
 * Não é erro de formulário: nada do que ele mandou está errado, e não há o que
 * corrigir e tentar de novo — outro avaliador chegou antes. Por isso sai como
 * **409 Conflito**, com um `code` que a tela usa para fechar a avaliação, avisar
 * e recarregar a lista, já sem o projeto e com o substituto no lugar.
 */
class ProjetoJaCobertoException extends Exception
{
    public const CODIGO = 'PROJETO_JA_COBERTO';

    public function __construct(string $message, public readonly bool $recebeuOutro = false)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODIGO,
            'errors' => [],
            'meta' => ['recebeu_outro' => $this->recebeuOutro],
        ], 409);
    }
}
