<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class MesclarInstituicaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    public function rules(): array
    {
        $origem = $this->route('instituicao');

        return [
            'destino_id' => [
                'required', 'integer', 'exists:instituicoes,id',
                function (string $attribute, mixed $value, Closure $fail) use ($origem) {
                    if ((int) $value === (int) $origem->id) {
                        $fail('Selecione uma instituição de destino diferente da que será mesclada.');
                    }
                },
            ],
        ];
    }
}
