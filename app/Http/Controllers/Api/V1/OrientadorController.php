<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orientador\RegisterOrientadorRequest;
use App\Http\Resources\CadastroPendenteResource;
use App\Services\ConfirmacaoCadastroService;
use Illuminate\Http\JsonResponse;

class OrientadorController extends Controller
{
    public function __construct(private readonly ConfirmacaoCadastroService $confirmacao) {}

    /**
     * Cadastro público de orientador (wizard 3 etapas). NÃO cria a conta ainda:
     * guarda o formulário e manda um código de 6 dígitos para o e-mail. O
     * usuário nasce em POST /cadastros/{token}/confirmar.
     */
    public function store(RegisterOrientadorRequest $request): JsonResponse
    {
        $pendente = $this->confirmacao->iniciar(Role::Orientador, $request->validated());

        return CadastroPendenteResource::make($pendente)
            ->additional(['meta' => [
                'message' => 'Enviamos um código de 6 dígitos para '.$pendente->email.'. Confirme para concluir o cadastro.',
            ]])
            ->response()
            ->setStatusCode(202);
    }
}
