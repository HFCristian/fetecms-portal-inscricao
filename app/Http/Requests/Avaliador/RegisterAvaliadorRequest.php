<?php

namespace App\Http\Requests\Avaliador;

use App\Http\Requests\Concerns\NormalizaEmail;
use App\Models\AvaliadorProfile;
use App\Models\Coorientador;
use App\Models\OrientadorProfile;
use App\Rules\CidadeDoEstado;
use App\Rules\Cpf;
use App\Rules\SubareaDaArea;
use App\Support\Idiomas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterAvaliadorRequest extends FormRequest
{
    use NormalizaEmail;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->limparEmails();

        if ($this->filled('cpf')) {
            $this->merge(['cpf' => preg_replace('/\D/', '', $this->input('cpf'))]);
        }

        // Ordem canônica e sem repetição: duas pessoas que marcaram os mesmos
        // idiomas gravam exatamente o mesmo valor. Código fora da lista é
        // descartado aqui — é defeito de cliente, não erro de quem preenche —,
        // e quem mandar **só** código desconhecido cai no `required` abaixo,
        // como quem não marcou nada.
        if ($this->has('idiomas')) {
            $this->merge(['idiomas' => Idiomas::normalizar($this->input('idiomas'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'cpf' => [
                'required', 'string', 'size:11', new Cpf,
                Rule::unique('avaliador_profiles', 'cpf'),
                // Exclusão mútua: CPF não pode ser de orientador nem de coorientador.
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (OrientadorProfile::where('cpf', $value)->exists()) {
                        $fail('Este CPF já está cadastrado como orientador. Um orientador não pode ser avaliador.');
                    } elseif (Coorientador::where('cpf', $value)->exists()) {
                        $fail('Este CPF consta como coorientador de um projeto e não pode ser avaliador.');
                    }
                },
            ],
            // Pós-graduação em andamento também habilita: a lista traz os dois casos.
            'titulacao' => ['required', 'string', Rule::in(AvaliadorProfile::TITULACOES)],
            // Camiseta é opcional: quem não responde fica fora da encomenda.
            'camiseta' => ['nullable', 'string', Rule::in(AvaliadorProfile::CAMISETAS)],
            // Idiomas, ao contrário da camiseta, são obrigatórios: é por eles
            // que a organização sabe quem pode avaliar um projeto estrangeiro,
            // e "nenhum idioma" não é uma resposta possível para um avaliador.
            'idiomas' => ['required', 'array', 'min:1'],
            'idiomas.*' => ['string', Rule::in(Idiomas::codigos())],
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            // subarea_nome cria uma subárea global nova (resolvida no service).
            'subarea_id' => ['nullable', 'integer', 'exists:subareas,id', new SubareaDaArea($this->input('area_id'))],
            'subarea_nome' => ['nullable', 'string', 'min:2', 'max:120'],
            // De onde o avaliador é: opcional no cadastro, completável no perfil.
            'estado_id' => ['nullable', 'integer', 'exists:estados,id'],
            'cidade_id' => ['nullable', 'integer', 'exists:cidades,id', new CidadeDoEstado($this->input('estado_id'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'titulacao.in' => 'Selecione uma titulação da lista. Pós-graduação em andamento também habilita.',
            'idiomas.required' => 'Selecione ao menos um idioma em que você pode avaliar.',
            'idiomas.min' => 'Selecione ao menos um idioma em que você pode avaliar.',
        ];
    }
}
