<?php

namespace App\Http\Requests\Admin;

use App\Enums\PublicoMala;
use App\Services\MalaDiretaService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Critério da mala direta: públicos marcados + lista personalizada. Vale para a
 * prévia (contagem/listagem/export) e é a base do disparo, para os dois sempre
 * enxergarem exatamente o mesmo recorte.
 *
 * O formato do e-mail personalizado NÃO é validado aqui de propósito: e-mail
 * torto precisa chegar ao relatório como "inválido" em vez de barrar o envio
 * inteiro.
 */
class PreviaMalaDiretaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $destinatarios = $this->input('destinatarios');

        // Aceita ["a@b.test", ...] além de [{email, nome}, ...].
        if (is_array($destinatarios)) {
            $this->merge([
                'destinatarios' => array_values(array_map(
                    fn ($item) => is_array($item) ? $item : ['email' => (string) $item],
                    $destinatarios,
                )),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'publicos' => ['sometimes', 'array'],
            'publicos.*' => [Rule::enum(PublicoMala::class)],
            'destinatarios' => ['sometimes', 'array', 'max:'.MalaDiretaService::MAX_PERSONALIZADOS],
            'destinatarios.*.email' => ['required', 'string', 'max:255'],
            'destinatarios.*.nome' => ['nullable', 'string', 'max:255'],
            'pagina' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'destinatarios.max' => 'A lista personalizada aceita no máximo :max e-mails por envio.',
            'destinatarios.*.email.required' => 'Informe o e-mail de cada destinatário da lista personalizada.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->publicos() === [] && $this->destinatarios() === []) {
                $validator->errors()->add(
                    'publicos',
                    'Escolha ao menos um público ou informe e-mails na lista personalizada.',
                );
            }
        });
    }

    /** @return array<int, string> */
    public function publicos(): array
    {
        return array_values(array_unique($this->validated('publicos') ?? []));
    }

    /** @return array<int, array{email: string, nome?: string|null}> */
    public function destinatarios(): array
    {
        return $this->validated('destinatarios') ?? [];
    }
}
