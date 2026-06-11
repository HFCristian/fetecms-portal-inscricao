<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubareaUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('nome')) {
            $this->merge(['nome' => trim(preg_replace('/\s+/', ' ', (string) $this->input('nome')))]);
        }
    }

    public function rules(): array
    {
        $subarea = $this->route('subarea');

        return [
            'nome' => [
                'required', 'string', 'min:2', 'max:120',
                // Único dentro da mesma área (índice único area_id, nome).
                Rule::unique('subareas', 'nome')->where('area_id', $subarea->area_id)->ignore($subarea->id),
            ],
        ];
    }
}
