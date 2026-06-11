<?php

namespace App\Http\Requests\Projeto;

use App\Enums\Categoria;
use App\Rules\SubareaDaArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Usada no create e no update. Como o projeto é salvável como rascunho, quase
 * tudo é opcional aqui; as obrigatoriedades de submissão ficam no
 * ProjetoChecklistService (Sprint 4). A autorização é feita por Policy no controller.
 */
class ProjetoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('palavras_chave'))) {
            $this->merge([
                'palavras_chave' => array_values(array_filter(array_map(
                    fn ($p) => trim(preg_replace('/\s+/', ' ', (string) $p)),
                    $this->input('palavras_chave'),
                ), fn ($p) => $p !== '')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'edicao_id' => ['nullable', 'integer', 'exists:edicoes,id'],
            'titulo' => ['nullable', 'string', 'max:255'],
            'categoria' => ['nullable', Rule::enum(Categoria::class)],
            'instituicao_id' => ['nullable', 'integer', 'exists:instituicoes,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'subarea_id' => ['nullable', 'integer', 'exists:subareas,id', new SubareaDaArea($this->input('area_id'))],
            'resumo' => ['nullable', 'string', 'max:5000'],
            'link_video' => ['nullable', 'url', 'max:255'],
            'palavras_chave' => ['nullable', 'array', 'max:5'],
            // Cada palavra-chave deve ter de 1 a 5 palavras.
            'palavras_chave.*' => ['string', 'max:60', 'regex:/^\S+(?:\s+\S+){0,4}$/u'],
            'pais' => ['nullable', 'string', 'max:60'],
            'estado_id' => ['nullable', 'integer', 'exists:estados,id'],
            'cidade_id' => ['nullable', 'integer', 'exists:cidades,id'],
            'estado_nome' => ['nullable', 'string', 'max:120'],
            'cidade_nome' => ['nullable', 'string', 'max:120'],
            'continuacao' => ['sometimes', 'boolean'],
            'tempo_pesquisa_meses' => ['nullable', 'integer', 'min:1', 'max:600'],
            'feira_afiliada' => ['sometimes', 'boolean'],
            'feira_afiliada_nome' => ['nullable', 'string', 'max:255'],
            'necessita_termo_etica' => ['sometimes', 'boolean'],
            'numero_credencial' => ['nullable', 'string', 'max:120'],
            'agenda_2030' => ['sometimes', 'boolean'],
            'categoria_agenda_2030' => ['nullable', 'string', 'max:120'],
            'email_comunicacao' => ['nullable', 'email', 'max:255'],
            'declaracao_email' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'palavras_chave.*.regex' => 'Cada palavra-chave deve ter de 1 a 5 palavras.',
        ];
    }
}
