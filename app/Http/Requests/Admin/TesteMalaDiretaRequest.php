<?php

namespace App\Http\Requests\Admin;

use App\Models\MalaDiretaArquivo;
use App\Services\MalaDiretaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Envio de teste da mala direta: a mensagem que o admin está escrevendo, para
 * os endereços que ele escolher.
 *
 * Aqui não há público nem justificativa — o teste não alcança a base, então o
 * que se pede é só a mensagem e para quem conferir. Os endereços são poucos de
 * propósito: isto é uma conferência de formatação, não um disparo.
 */
class TesteMalaDiretaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $destinatarios = $this->input('destinatarios');

        // Aceita ["a@b.test", ...] além de [{email, nome}, ...], como a prévia.
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
            'assunto' => ['required', 'string', 'max:200'],
            'corpo' => ['required', 'string', 'max:60000'],
            'formato' => ['nullable', Rule::in(['texto', 'html'])],
            'imagens' => ['nullable', 'array', 'max:'.MalaDiretaArquivo::MAX_IMAGENS],
            'imagens.*' => ['integer', 'exists:mala_direta_arquivos,id'],
            'anexos' => ['nullable', 'array', 'max:'.MalaDiretaArquivo::MAX_ANEXOS],
            'anexos.*' => ['integer', 'exists:mala_direta_arquivos,id'],
            'destinatarios' => ['required', 'array', 'min:1', 'max:'.MalaDiretaService::MAX_TESTE],
            'destinatarios.*.email' => ['required', 'string', 'max:255'],
            'destinatarios.*.nome' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'assunto' => 'assunto da mensagem',
            'corpo' => 'texto da mensagem',
            'destinatarios' => 'e-mails do teste',
        ];
    }

    public function messages(): array
    {
        return [
            'destinatarios.required' => 'Informe ao menos um e-mail para receber o teste.',
            'destinatarios.max' => 'O envio de teste aceita no máximo :max e-mails.',
            'destinatarios.*.email.required' => 'Informe o e-mail de cada destinatário do teste.',
        ];
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        return [
            'assunto' => $this->validated('assunto'),
            'corpo' => $this->validated('corpo'),
            'formato' => $this->validated('formato') ?? 'texto',
            'imagens' => array_map('intval', $this->validated('imagens') ?? []),
            'anexos' => array_map('intval', $this->validated('anexos') ?? []),
            'destinatarios' => $this->validated('destinatarios'),
        ];
    }
}
