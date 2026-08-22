<?php

namespace App\Http\Requests\Avaliador;

use App\Rules\SubareaDaArea;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Troca de área/subárea do avaliador no perfil: a área é obrigatória e a
 * subárea é opcional, sempre por id — o avaliador está autenticado, então uma
 * subárea nova nasce pelo próprio combobox (POST /catalogos/subareas) antes de
 * chegar aqui. A janela em que a troca é permitida é regra de negócio e fica
 * no AvaliadorService.
 */
class AtualizarClassificacaoRequest extends FormRequest
{
    /** Autorização é da rota (role:avaliador) — cada um só edita o próprio perfil. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'subarea_id' => ['nullable', 'integer', 'exists:subareas,id', new SubareaDaArea($this->input('area_id'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'area_id' => 'área de atuação',
            'subarea_id' => 'subárea',
        ];
    }
}
