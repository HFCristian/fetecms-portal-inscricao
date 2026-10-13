<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Avaliador\RegisterAvaliadorRequest;
use Illuminate\Support\Arr;

/**
 * Avaliação presencial → Cadastro de avaliadores (Sprint 191): as mesmas
 * regras do cadastro público de avaliador — inclusive a exclusão mútua com
 * orientador e coorientador pelo CPF e pelo e-mail —, menos a senha (gerada
 * pelo portal), a camiseta, os idiomas, a cidade e o estado.
 */
class CadastrarAvaliadorPresencialRequest extends RegisterAvaliadorRequest
{
    public function rules(): array
    {
        return Arr::except(parent::rules(), ['password', 'camiseta', 'idiomas', 'idiomas.*', 'estado_id', 'cidade_id']);
    }
}
