<?php

namespace App\Http\Requests\Avaliador;

use App\Models\Avaliacao;
use App\Models\Projeto;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base do preenchimento da avaliação (rubrica + conferência da classificação).
 * O rascunho não exige nada; a conclusão exige as três notas e a conferência da
 * área. As regras comuns — faixa das notas, tamanho dos comentários e
 * consistência das sugestões — valem nos dois casos.
 */
abstract class AvaliacaoRequest extends FormRequest
{
    /** Autorização é do controller (dono da avaliação + liberação da edição). */
    public function authorize(): bool
    {
        return true;
    }

    /** No rascunho tudo é opcional; ao concluir, os quesitos e a área são exigidos. */
    abstract protected function obrigatorio(): bool;

    public function rules(): array
    {
        $exigido = $this->obrigatorio() ? ['required'] : ['sometimes', 'nullable'];

        $regras = [
            'area_correta' => [...$exigido, 'boolean'],
            'area_sugerida_id' => [
                Rule::requiredIf(fn () => $this->obrigatorio() && $this->marcouIncorreta('area_correta')),
                'nullable', 'integer', Rule::exists('areas', 'id'),
                $this->naoPodeRepetirOAtual('area_id'),
            ],
            // Conferir a subárea é opcional mesmo ao concluir.
            'subarea_correta' => ['sometimes', 'nullable', 'boolean'],
            'subarea_sugerida_id' => [
                Rule::requiredIf(fn () => $this->obrigatorio() && $this->marcouIncorreta('subarea_correta')),
                'nullable', 'integer', Rule::exists('subareas', 'id'),
                $this->naoPodeRepetirOAtual('subarea_id'),
            ],
        ];

        foreach (Avaliacao::QUESITOS as $quesito) {
            $regras["nota_{$quesito}"] = [...$exigido, 'integer', 'min:0', 'max:'.Avaliacao::NOTA_MAXIMA_QUESITO];
            $regras["comentario_{$quesito}"] = ['sometimes', 'nullable', 'string', 'max:2000'];
        }

        return $regras;
    }

    /** O avaliador respondeu explicitamente "não está correta". */
    private function marcouIncorreta(string $campo): bool
    {
        return $this->has($campo) && $this->input($campo) !== null && ! $this->boolean($campo);
    }

    /** Sugerir exatamente o que o projeto já tem não corrige nada. */
    private function naoPodeRepetirOAtual(string $colunaDoProjeto): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falhar) use ($colunaDoProjeto) {
            if ($valor && (int) $valor === (int) $this->projeto()?->{$colunaDoProjeto}) {
                $falhar('A sugestão precisa ser diferente da classificação atual do projeto.');
            }
        };
    }

    private function projeto(): ?Projeto
    {
        $avaliacao = $this->route('avaliacao');

        return $avaliacao instanceof Avaliacao ? $avaliacao->projeto : null;
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
            'area_correta' => 'conferência da área do conhecimento',
            'area_sugerida_id' => 'área correta sugerida',
            'subarea_correta' => 'conferência da subárea',
            'subarea_sugerida_id' => 'subárea correta sugerida',
        ];
    }
}
