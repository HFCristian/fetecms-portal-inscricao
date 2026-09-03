<?php

namespace App\Http\Requests\Avaliador;

use App\Support\Rubrica;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edição do parecer final de uma avaliação **já enviada**: só as duas
 * recomendações escritas (vídeo e projeto) e a justificativa da correção.
 *
 * As notas e a conferência de classificação ficam de fora de propósito — mexer
 * nelas mudaria a nota final e o ranking depois do envio, que é irreversível.
 */
class EditarParecerRequest extends FormRequest
{
    /** Autorização é do controller (dono da avaliação + período em aberto). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $regras = ['justificativa' => ['required', 'string', 'min:5', 'max:500']];

        foreach (Rubrica::COMENTARIOS as $campo) {
            $regras[$campo] = ['sometimes', 'nullable', 'string', 'max:2000'];
        }

        return $regras;
    }

    /** Texto só de espaços vira nulo — é assim que se apaga uma recomendação. */
    protected function prepareForValidation(): void
    {
        $limpos = [];

        foreach (Rubrica::COMENTARIOS as $campo) {
            if ($this->has($campo)) {
                $limpos[$campo] = trim((string) $this->input($campo)) ?: null;
            }
        }

        $this->merge($limpos);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'comentario_video' => 'recomendações sobre o vídeo',
            'comentario_projeto' => 'recomendações sobre o projeto',
            'justificativa' => 'justificativa da alteração',
        ];
    }
}
