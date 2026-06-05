<?php

namespace App\Http\Requests\Projeto;

use App\Enums\TipoDocumento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:pdf,docx', 'max:10240'], // 10 MB
            'tipo' => ['required', Rule::enum(TipoDocumento::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'O arquivo não pode ultrapassar 10 MB.',
            'file.mimes' => 'O arquivo deve ser PDF ou DOCX.',
        ];
    }
}
