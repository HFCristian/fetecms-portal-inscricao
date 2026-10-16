<?php

namespace App\Http\Requests\Admin;

use App\Enums\TipoInterprete;
use App\Models\Edicao;
use App\Models\InterpreteVinculo;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cadastro de intérprete (Sprint 214): só nome e CPF são obrigatórios. O CPF
 * não se repete na edição. Quem marca "Outros" diz qual.
 */
class SalvarInterpreteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => trim((string) $this->input('nome')),
            'cpf' => preg_replace('/\D/', '', (string) $this->input('cpf')),
            'data_nascimento' => $this->input('data_nascimento') ?: null,
            'tipos' => array_values((array) $this->input('tipos', [])),
            'vinculos' => array_values((array) $this->input('vinculos', [])),
        ]);
    }

    public function rules(): array
    {
        $interprete = $this->route('interprete');

        return [
            'nome' => ['required', 'string', 'max:200'],
            'cpf' => [
                'required', 'string', 'size:11', new Cpf,
                Rule::unique('interpretes', 'cpf')
                    ->where('edicao_id', Edicao::atual()?->id)
                    ->ignore($interprete?->id),
            ],
            'data_nascimento' => ['nullable', 'date', 'before:today'],
            'tipos' => ['array'],
            'tipos.*' => ['distinct', Rule::in(TipoInterprete::valores())],
            'outra_lingua' => ['nullable', 'string', 'max:120'],
            'outro_pcd' => ['nullable', 'string', 'max:120'],
            'vinculos' => ['array', 'max:50'],
            'vinculos.*.projeto_id' => ['required', 'integer'],
            'vinculos.*.pessoa_tipo' => ['nullable', Rule::in(InterpreteVinculo::PESSOAS)],
            'vinculos.*.pessoa_id' => ['nullable', 'integer', 'required_with:vinculos.*.pessoa_tipo'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $tipos = (array) $this->input('tipos', []);

                if (in_array(TipoInterprete::LinguaOutra->value, $tipos, true) && trim((string) $this->input('outra_lingua')) === '') {
                    $validator->errors()->add('outra_lingua', 'Diga qual é a outra língua.');
                }

                if (in_array(TipoInterprete::PcdOutro->value, $tipos, true) && trim((string) $this->input('outro_pcd')) === '') {
                    $validator->errors()->add('outro_pcd', 'Diga qual é o outro atendimento.');
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'nome' => 'nome',
            'cpf' => 'CPF',
            'data_nascimento' => 'data de nascimento',
            'tipos.*' => 'tipo de intérprete',
        ];
    }

    public function messages(): array
    {
        return [
            'cpf.unique' => 'Já existe um intérprete com este CPF nesta edição.',
            'data_nascimento.before' => 'A data de nascimento precisa ser anterior a hoje.',
        ];
    }
}
