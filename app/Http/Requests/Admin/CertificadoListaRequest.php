<?php

namespace App\Http\Requests\Admin;

use App\Services\CertificadoListaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * O que o assistente de certificados grava a cada passo (Sprint 259): o nome
 * da lista, o passo em que parou e a configuração até ali. A configuração pode
 * chegar incompleta — é rascunho —, e o serviço normaliza o resto; quem cobra
 * a carga de cada categoria é a geração.
 */
class CertificadoListaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin e aba:certificados
    }

    public function rules(): array
    {
        $modos = [CertificadoListaService::MODO_FIXA, CertificadoListaService::MODO_POR_PROJETO, CertificadoListaService::MODO_POR_TURNO];

        return [
            'nome' => ['nullable', 'string', 'max:120'],
            'passo' => ['nullable', 'integer', 'min:0', 'max:'.CertificadoListaService::ULTIMO_PASSO],
            'config' => ['nullable', 'array'],
            'config.fases' => ['nullable', 'array'],
            'config.fases.*' => [Rule::in(array_keys(CertificadoListaService::FASES))],
            'config.publicos' => ['nullable', 'array'],
            'config.publicos.*' => [Rule::in(array_keys(CertificadoListaService::PUBLICOS))],
            'config.categorias' => ['nullable', 'array'],
            'config.categorias.*' => ['array'],
            'config.categorias.*.modo' => ['nullable', Rule::in($modos)],
            // Até 1.000 horas: o bastante para qualquer certificado, e o que
            // barra um zero a mais digitado sem querer.
            'config.categorias.*.minutos' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'config.categorias.*.teto_minutos' => ['nullable', 'integer', 'min:1', 'max:60000'],
            'config.categorias.*.data_inicio' => ['nullable', 'date_format:Y-m-d'],
            'config.categorias.*.data_fim' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function attributes(): array
    {
        return [
            'config.categorias.*.minutos' => 'carga horária',
            'config.categorias.*.teto_minutos' => 'limite de horas',
            'config.categorias.*.data_inicio' => 'data de início',
            'config.categorias.*.data_fim' => 'data de fim',
        ];
    }
}
