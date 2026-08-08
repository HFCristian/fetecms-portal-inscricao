<?php

namespace App\Http\Requests\Avaliador;

use App\Models\Avaliacao;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rubrica de conclusão da avaliação: os três quesitos são obrigatórios (0 a 10)
 * e cada comentário é opcional. A nota final (soma) é calculada no service — o
 * cliente não a envia.
 */
class ConcluirAvaliacaoRequest extends FormRequest
{
    /** Autorização é do controller (dono da avaliação + liberação da edição). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $nota = ['required', 'integer', 'min:0', 'max:'.Avaliacao::NOTA_MAXIMA_QUESITO];
        $comentario = ['nullable', 'string', 'max:2000'];

        return [
            'nota_video' => $nota,
            'comentario_video' => $comentario,
            'nota_resumo' => $nota,
            'comentario_resumo' => $comentario,
            'nota_pesquisa' => $nota,
            'comentario_pesquisa' => $comentario,
        ];
    }

    /** Comentário só de espaços entra como nulo, não como string vazia. */
    protected function prepareForValidation(): void
    {
        $limpos = [];

        foreach (Avaliacao::QUESITOS as $quesito) {
            $campo = "comentario_{$quesito}";

            if ($this->has($campo)) {
                $limpos[$campo] = trim((string) $this->input($campo)) ?: null;
            }
        }

        $this->merge($limpos);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nota_video' => 'nota do vídeo de apresentação',
            'comentario_video' => 'comentário sobre o vídeo de apresentação',
            'nota_resumo' => 'nota do resumo do projeto',
            'comentario_resumo' => 'comentário sobre o resumo do projeto',
            'nota_pesquisa' => 'nota do projeto de pesquisa',
            'comentario_pesquisa' => 'comentário sobre o projeto de pesquisa',
        ];
    }
}
