<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Avaliador\RegisterAvaliadorRequest;
use App\Http\Resources\CadastroPendenteResource;
use App\Services\ConfirmacaoCadastroService;
use Illuminate\Http\JsonResponse;

class AvaliadorController extends Controller
{
    public function __construct(private readonly ConfirmacaoCadastroService $confirmacao) {}

    /**
     * Cadastro público de avaliador. Como o do orientador, para no código de
     * confirmação: a exclusão mútua com orientador/coorientador é validada no
     * RegisterAvaliadorRequest e conferida de novo na confirmação.
     */
    public function store(RegisterAvaliadorRequest $request): JsonResponse
    {
        $pendente = $this->confirmacao->iniciar(Role::Avaliador, $request->validated());

        return CadastroPendenteResource::make($pendente)
            ->additional(['meta' => [
                'message' => 'Enviamos um código de 6 dígitos para '.$pendente->email.'. Confirme para concluir o cadastro.',
            ]])
            ->response()
            ->setStatusCode(202);
    }
}
