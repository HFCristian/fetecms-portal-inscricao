<?php

namespace App\Http\Requests\Admin;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class MesclarAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    public function rules(): array
    {
        $origemId = $this->route('area')->id;

        return [
            'destino_id' => [
                'required', 'integer', 'exists:areas,id',
                function (string $attribute, mixed $value, Closure $fail) use ($origemId) {
                    if ((int) $value === (int) $origemId) {
                        $fail('Selecione uma área de destino diferente da que será mesclada.');
                    }
                },
            ],
        ];
    }
}
