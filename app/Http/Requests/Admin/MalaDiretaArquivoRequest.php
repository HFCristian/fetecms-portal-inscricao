<?php

namespace App\Http\Requests\Admin;

use App\Models\MalaDiretaArquivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload de uma imagem do corpo ou de um anexo da mala direta. O limite de
 * tamanho depende do tipo (imagem 10MB, anexo 20MB); a quantidade por mensagem
 * é conferida no disparo.
 */
class MalaDiretaArquivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    public function rules(): array
    {
        $tipo = $this->input('tipo');
        $ehImagem = $tipo === MalaDiretaArquivo::TIPO_IMAGEM;

        return [
            'tipo' => ['required', Rule::in([MalaDiretaArquivo::TIPO_IMAGEM, MalaDiretaArquivo::TIPO_ANEXO])],
            'arquivo' => array_filter([
                'required', 'file',
                'max:'.MalaDiretaArquivo::tamanhoMaximoDe((string) $tipo),
                // Imagem do corpo precisa ser imagem mesmo; anexo aceita
                // documento, planilha e as próprias imagens.
                $ehImagem ? 'mimes:jpg,jpeg,png,gif,webp' : 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip',
            ]),
        ];
    }

    public function attributes(): array
    {
        return ['arquivo' => 'arquivo'];
    }
}
