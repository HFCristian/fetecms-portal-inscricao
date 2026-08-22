<?php

namespace App\Http\Requests\Admin;

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
            'corpo' => ['required', 'string', 'max:20000'],
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
            'publicos' => $this->publicos(),
            'destinatarios' => $this->destinatarios(),
        ];
    }
}
