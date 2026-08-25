<?php

namespace App\Http\Requests\Admin;

use App\Enums\PublicoMala;
use App\Services\PublicoUsuariosService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AvisoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'titulo' => trim((string) $this->input('titulo')),
            'mensagem' => trim((string) $this->input('mensagem')),
        ]);
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:120'],
            'mensagem' => ['required', 'string', 'max:2000'],
            // Públicos combináveis, como na mala direta. Sem nenhum, o aviso vai
            // para todos os orientadores (o comportamento de sempre).
            'publicos' => ['nullable', 'array'],
            'publicos.*' => [Rule::enum(PublicoMala::class)],
            // Data em que o card sai da tela sozinho.
            'expira_em' => ['nullable', 'date', 'after:now'],
        ];
    }

    /** @return array<int, PublicoMala> */
    public function publicos(): array
    {
        return app(PublicoUsuariosService::class)->normalizar($this->validated('publicos') ?? []);
    }

    /** Data de expiração como hora de parede de Campo Grande (sem shift de UTC). */
    public function expiraEm(): ?Carbon
    {
        $valor = $this->validated('expira_em');

        return ($valor === null || $valor === '') ? null : Carbon::parse($valor, config('app.timezone'));
    }

    public function messages(): array
    {
        return [
            'titulo.required' => 'Escreva o título do aviso.',
            'titulo.max' => 'O título pode ter no máximo 120 caracteres.',
            'mensagem.required' => 'Escreva a mensagem do aviso.',
            'mensagem.max' => 'A mensagem pode ter no máximo 2000 caracteres.',
            'expira_em.after' => 'A data de expiração precisa estar no futuro.',
        ];
    }
}
