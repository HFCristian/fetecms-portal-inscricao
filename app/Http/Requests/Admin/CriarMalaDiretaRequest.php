<?php

namespace App\Http\Requests\Admin;

use App\Models\MalaDiretaArquivo;
use Illuminate\Validation\Rule;

/**
 * Disparo da mala direta: o critério de destinatários (herdado) mais os campos
 * do comunicado. O admin já confirmou a mensagem na tela antes de chegar aqui.
 */
class CriarMalaDiretaRequest extends PreviaMalaDiretaRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'nome' => ['required', 'string', 'max:120'],
            'justificativa' => ['required', 'string', 'max:2000'],
            'solicitante' => ['nullable', 'string', 'max:160'],
            'assunto' => ['required', 'string', 'max:200'],
            'corpo' => ['required', 'string', 'max:60000'],
            // O editor manda HTML; malas antigas (e a API) continuam aceitando texto.
            'formato' => ['nullable', Rule::in(['texto', 'html'])],
            'imagens' => ['nullable', 'array', 'max:'.MalaDiretaArquivo::MAX_IMAGENS],
            'imagens.*' => ['integer', 'exists:mala_direta_arquivos,id'],
            'anexos' => ['nullable', 'array', 'max:'.MalaDiretaArquivo::MAX_ANEXOS],
            'anexos.*' => ['integer', 'exists:mala_direta_arquivos,id'],
        ]);
    }

    public function attributes(): array
    {
        return [
            'nome' => 'nome da mala',
            'justificativa' => 'justificativa de envio',
            'solicitante' => 'solicitante de envio',
            'assunto' => 'assunto da mensagem',
            'corpo' => 'texto da mensagem',
            'imagens' => 'imagens da mensagem',
            'anexos' => 'anexos da mensagem',
        ];
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        return [
            'nome' => $this->validated('nome'),
            'justificativa' => $this->validated('justificativa'),
            'solicitante' => $this->validated('solicitante'),
            'assunto' => $this->validated('assunto'),
            'corpo' => $this->validated('corpo'),
            'formato' => $this->validated('formato') ?? 'texto',
            'imagens' => array_map('intval', $this->validated('imagens') ?? []),
            'anexos' => array_map('intval', $this->validated('anexos') ?? []),
            'publicos' => $this->publicos(),
            'destinatarios' => $this->destinatarios(),
        ];
    }
}
