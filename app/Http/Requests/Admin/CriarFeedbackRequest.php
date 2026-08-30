<?php

namespace App\Http\Requests\Admin;

use App\Enums\PublicoMala;
use App\Enums\TipoPerguntaFeedback;
use App\Enums\UnidadeLimiteResposta;
use App\Support\ModelosAlternativas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação de um pedido de feedback: título, públicos e as perguntas.
 *
 * As regras que dependem do **tipo** da pergunta ficam no `after()`, porque a
 * validação de cada campo muda conforme ele: alternativa exige opções (ou um
 * modelo), dissertativa exige que o máximo não seja menor que o mínimo.
 */
class CriarFeedbackRequest extends FormRequest
{
    /** Teto de perguntas: questionário longo derruba a taxa de resposta. */
    public const MAX_PERGUNTAS = 20;

    public const MAX_OPCOES = 12;

    public function authorize(): bool
    {
        return true; // rota protegida por role:admin + aba:comunicacao
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:150'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'publicos' => ['required', 'array', 'min:1'],
            'publicos.*' => [Rule::in(array_column(PublicoMala::cases(), 'value'))],

            'perguntas' => ['required', 'array', 'min:1', 'max:'.self::MAX_PERGUNTAS],
            'perguntas.*.enunciado' => ['required', 'string', 'max:500'],
            'perguntas.*.tipo' => ['required', Rule::in(array_column(TipoPerguntaFeedback::cases(), 'value'))],
            'perguntas.*.obrigatoria' => ['boolean'],

            // Alternativas: opções digitadas OU um modelo pronto do catálogo.
            'perguntas.*.opcoes' => ['array', 'max:'.self::MAX_OPCOES],
            'perguntas.*.opcoes.*' => ['string', 'max:200'],
            'perguntas.*.modelo' => ['nullable', Rule::in(array_keys(ModelosAlternativas::MODELOS))],

            // Dissertativas: limite em palavras ou caracteres.
            'perguntas.*.unidade' => ['nullable', Rule::in(array_column(UnidadeLimiteResposta::cases(), 'value'))],
            'perguntas.*.minimo' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'perguntas.*.maximo' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function ($validator) {
                foreach ((array) $this->input('perguntas', []) as $i => $pergunta) {
                    $this->conferirPergunta($validator, $i, (array) $pergunta);
                }
            },
        ];
    }

    /** @param array<string, mixed> $pergunta */
    private function conferirPergunta($validator, int|string $i, array $pergunta): void
    {
        $alternativa = ($pergunta['tipo'] ?? null) === TipoPerguntaFeedback::Alternativa->value;

        if ($alternativa) {
            $opcoes = array_filter(
                array_map(fn ($o) => trim((string) $o), (array) ($pergunta['opcoes'] ?? [])),
                fn (string $o) => $o !== '',
            );

            if (empty($pergunta['modelo']) && count($opcoes) < 2) {
                $validator->errors()->add(
                    "perguntas.{$i}.opcoes",
                    'Escreva ao menos duas alternativas ou escolha um modelo pronto.',
                );
            }

            return;
        }

        $min = $pergunta['minimo'] ?? null;
        $max = $pergunta['maximo'] ?? null;

        if ($min !== null && $max !== null && (int) $max < (int) $min) {
            $validator->errors()->add(
                "perguntas.{$i}.maximo",
                'O máximo não pode ser menor que o mínimo.',
            );
        }
    }

    public function attributes(): array
    {
        return [
            'titulo' => 'título',
            'publicos' => 'públicos',
            'perguntas' => 'perguntas',
        ];
    }
}
