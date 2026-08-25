<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mínimos da avaliação online. Cada card da tela salva o seu número, então os
 * dois campos são opcionais — mas ao menos um precisa vir.
 */
class MinimosAvaliacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    public function rules(): array
    {
        return [
            'min_por_avaliador' => ['required_without:min_por_projeto', 'integer', 'min:1', 'max:50'],
            'min_por_projeto' => ['required_without:min_por_avaliador', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function attributes(): array
    {
        return [
            'min_por_avaliador' => 'mínimo de avaliações por avaliador',
            'min_por_projeto' => 'mínimo de avaliações por projeto',
        ];
    }
}
