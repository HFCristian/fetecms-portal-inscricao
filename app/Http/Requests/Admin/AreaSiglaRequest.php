<?php

namespace App\Http\Requests\Admin;

use App\Models\Area;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sigla de três letras da área, usada no código dos projetos na lista final
 * (FET.AGR-001). Campo em branco tira a sigla — aí a lista cai para as três
 * primeiras letras do nome.
 */
class AreaSiglaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    /** Campo vazio na tela chega como string vazia: vira null. Sigla é sempre maiúscula. */
    protected function prepareForValidation(): void
    {
        $sigla = $this->input('sigla');

        $this->merge([
            'sigla' => $sigla === '' || $sigla === null ? null : mb_strtoupper(trim((string) $sigla)),
        ]);
    }

    public function rules(): array
    {
        return [
            'sigla' => [
                'present', 'nullable', 'string',
                'size:'.Area::TAMANHO_SIGLA,
                'regex:/^[A-Z]+$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'sigla.regex' => 'A sigla usa só letras (A a Z), sem acento.',
        ];
    }

    public function attributes(): array
    {
        return ['sigla' => 'sigla da área'];
    }

    public function sigla(): ?string
    {
        return $this->validated()['sigla'] ?? null;
    }
}
