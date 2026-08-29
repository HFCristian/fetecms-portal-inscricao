<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ModeloEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assunto' => ['required', 'string', 'max:200'],
            'corpo' => ['required', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return ['corpo' => 'texto'];
    }
}
