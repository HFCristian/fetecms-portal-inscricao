<?php

namespace App\Http\Requests\Admin;

use App\Enums\GrupoCorrelato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AreaCorrelacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    /** Campo vazio na tela ("Sem correlação") chega como string vazia: vira null. */
    protected function prepareForValidation(): void
    {
        if ($this->input('grupo_correlato') === '') {
            $this->merge(['grupo_correlato' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'grupo_correlato' => ['present', 'nullable', Rule::enum(GrupoCorrelato::class)],
        ];
    }

    public function attributes(): array
    {
        return ['grupo_correlato' => 'grupo de áreas correlatas'];
    }

    public function grupo(): ?GrupoCorrelato
    {
        $valor = $this->validated()['grupo_correlato'] ?? null;

        return $valor === null ? null : GrupoCorrelato::from($valor);
    }
}
