<?php

namespace App\Http\Requests\Avaliador;

use App\Models\Avaliacao;
use App\Models\Projeto;
use App\Support\Rubrica;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base do preenchimento da avaliação (respostas da rubrica, conferência da
 * classificação e recomendações). O rascunho não exige nada; a conclusão exige
 * todas as perguntas pontuadas e a conferência da área. As regras comuns —
 * valores dentro da escala, tamanho dos textos e consistência das sugestões —
 * valem nos dois casos.
 */
abstract class AvaliacaoRequest extends FormRequest
{
    /** Autorização é do controller (dono da avaliação + liberação da edição). */
    public function authorize(): bool
    {
        return true;
    }

    /** No rascunho tudo é opcional; ao concluir, as perguntas e a área são exigidas. */
    abstract protected function obrigatorio(): bool;

    public function rules(): array
    {
        $exigido = $this->obrigatorio() ? ['required'] : ['sometimes', 'nullable'];

        $regras = [
            'respostas' => [...$exigido, 'array', $this->somenteChavesDaRubrica()],
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

        foreach (Rubrica::COMENTARIOS as $campo) {
            $regras[$campo] = ['sometimes', 'nullable', 'string', 'max:2000'];
        }

        foreach (Rubrica::perguntas() as $pergunta) {
            $regras["respostas.{$pergunta['chave']}"] = [
                ...$exigido,
                ...($pergunta['tipo'] === Rubrica::TIPO_SIM_NAO
                    ? ['boolean']
                    : ['integer', Rule::in(array_keys(Rubrica::ESCALA))]),
            ];
        }

        return $regras;
    }

    /** Resposta de pergunta que não existe na rubrica não entra. */
    private function somenteChavesDaRubrica(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falhar) {
            $desconhecidas = array_diff(array_keys((array) $valor), Rubrica::chaves());

            if ($desconhecidas !== []) {
                $falhar('Há respostas de perguntas que não fazem parte da rubrica.');
            }
        };
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

    /**
     * Texto só de espaços entra como nulo, não como string vazia; e resposta
     * vazia some do array, para o rascunho não gravar buraco nem quebrar a
     * validação de escala.
     */
    protected function prepareForValidation(): void
    {
        $limpos = [];

        foreach (Rubrica::COMENTARIOS as $campo) {
            if ($this->has($campo)) {
                $limpos[$campo] = trim((string) $this->input($campo)) ?: null;
            }
        }

        if (is_array($this->input('respostas'))) {
            $limpos['respostas'] = array_filter(
                $this->input('respostas'),
                fn (mixed $valor) => $valor !== null && $valor !== '',
            );
        }

        $this->merge($limpos);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $atributos = [
            'respostas' => 'respostas',
            'comentario_video' => 'recomendações sobre o vídeo',
            'comentario_projeto' => 'recomendações sobre o projeto',
            'area_correta' => 'conferência da área do conhecimento',
            'area_sugerida_id' => 'área correta sugerida',
            'subarea_correta' => 'conferência da subárea',
            'subarea_sugerida_id' => 'subárea correta sugerida',
        ];

        foreach (Rubrica::perguntas() as $pergunta) {
            $atributos["respostas.{$pergunta['chave']}"] = "resposta sobre {$pergunta['rotulo']}";
        }

        return $atributos;
    }
}
