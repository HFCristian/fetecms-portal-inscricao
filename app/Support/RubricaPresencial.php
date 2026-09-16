<?php

namespace App\Support;

/**
 * Rubrica da **avaliação presencial** — a que o avaliador responde no estande,
 * no dia da feira.
 *
 * É **outra** rubrica, e não a das 17 perguntas da avaliação online, porque o
 * que se avalia no estande é outra coisa: lá se lia o projeto escrito e o
 * vídeo; aqui se vê a equipe apresentar, responder pergunta e defender o que
 * fez. Metodologia e referências já foram julgadas — repeti-las no corredor
 * seria pedir ao avaliador presencial que refizesse, de memória e em cinco
 * minutos, um trabalho que outra pessoa fez com o documento na mão.
 *
 * A nota é **separada**: ela não entra no ranking da avaliação online (que
 * define a lista final e já está fechado quando o evento começa) e serve à
 * **premiação** — é ela que a organização usa para decidir quem leva as
 * credenciais.
 *
 * A mecânica é a mesma da rubrica online, de propósito: escala de 0 a 10 de
 * dois em dois, pesos que somam 10,00 e a nota como soma ponderada calculada no
 * servidor. Quem já avaliou online não precisa aprender outra régua.
 */
final class RubricaPresencial
{
    /** Escala das perguntas (valor => rótulo) — a mesma da rubrica online. */
    public const ESCALA = [
        0 => 'Não possui',
        2 => 'Muito ruim',
        4 => 'Ruim',
        6 => 'Regular',
        8 => 'Bom',
        10 => 'Muito bom',
    ];

    /** Nota máxima — a soma dos pesos. */
    public const NOTA_MAXIMA = 10.0;

    /**
     * As seções, na ordem em que o avaliador as percorre no estande.
     *
     * Os pesos seguem o que a organização enxerga da bancada: a apresentação e
     * o domínio do tema valem mais do que o material exposto, porque é a equipe
     * que está sendo avaliada, não o banner.
     */
    public const SECOES = [
        [
            'chave' => 'apresentacao',
            'titulo' => 'Apresentação',
            'icone' => 'record_voice_over',
            'ajuda' => 'Clareza, sequência e tempo: a equipe consegue contar o que fez a quem chega sem saber nada do projeto?',
            'perguntas' => [
                [
                    'chave' => 'clareza',
                    'texto' => 'De que modo a equipe apresentou o trabalho com clareza e sequência lógica?',
                    'ajuda' => 'Considere se o problema, o método e os resultados aparecem numa ordem que se acompanha.',
                    'peso' => 1.5,
                ],
                [
                    'chave' => 'participacao',
                    'texto' => 'De que modo todos os integrantes participaram da apresentação?',
                    'ajuda' => 'O trabalho é da equipe: um aluno que não fala não necessariamente não participou, mas a apresentação deve ser de todos.',
                    'peso' => 1.0,
                ],
            ],
        ],
        [
            'chave' => 'dominio',
            'titulo' => 'Domínio do tema',
            'icone' => 'psychology',
            'ajuda' => 'O que a equipe sabe além do roteiro decorado.',
            'perguntas' => [
                [
                    'chave' => 'conhecimento',
                    'texto' => 'De que modo a equipe demonstrou domínio do conteúdo do projeto?',
                    'ajuda' => 'Conceitos, escolhas metodológicas e limitações do próprio trabalho.',
                    'peso' => 2.0,
                ],
                [
                    'chave' => 'perguntas',
                    'texto' => 'De que modo a equipe respondeu às perguntas feitas durante a visita?',
                    'ajuda' => '"Não sabemos" com honestidade vale mais do que uma resposta inventada.',
                    'peso' => 1.5,
                ],
            ],
        ],
        [
            'chave' => 'estande',
            'titulo' => 'Estande e material',
            'icone' => 'storefront',
            'ajuda' => 'O que está exposto e como ajuda (ou atrapalha) a entender o projeto.',
            'perguntas' => [
                [
                    'chave' => 'organizacao',
                    'texto' => 'De que modo o estande está organizado e o material exposto é legível?',
                    'ajuda' => 'Banner, protótipo e material de apoio — considere quem lê de longe.',
                    'peso' => 1.0,
                ],
                [
                    'chave' => 'recursos',
                    'texto' => 'De que modo os recursos do estande (protótipo, experimento, amostras) sustentam o que é dito?',
                    'ajuda' => 'Um projeto sem protótipo não é pior por isso: avalie o que existe, não o que falta ter.',
                    'peso' => 1.0,
                ],
            ],
        ],
        [
            'chave' => 'resultados',
            'titulo' => 'Resultados e relevância',
            'icone' => 'insights',
            'ajuda' => 'O que o trabalho alcançou e para quem isso importa.',
            'perguntas' => [
                [
                    'chave' => 'resultados',
                    'texto' => 'De que modo os resultados apresentados sustentam as conclusões da equipe?',
                    'ajuda' => 'Conclusão maior do que o dado obtido é o erro mais comum nesta faixa.',
                    'peso' => 1.5,
                ],
                [
                    'chave' => 'relevancia',
                    'texto' => 'De que modo o trabalho é relevante para a comunidade ou para a área?',
                    'ajuda' => 'Aplicação prática, impacto local ou contribuição para o conhecimento da área.',
                    'peso' => 0.5,
                ],
            ],
        ],
        [
            'chave' => 'final',
            'titulo' => 'Parecer',
            'icone' => 'rate_review',
            'ajuda' => 'O que a equipe leva da visita além da nota.',
            'componente' => 'comentarios',
            'perguntas' => [],
        ],
    ];

    /** Campo descritivo (não vale ponto). */
    public const COMENTARIO = 'comentario';

    /**
     * Todas as perguntas, na ordem, já com a seção de cada uma.
     *
     * @return list<array<string, mixed>>
     */
    public static function perguntas(): array
    {
        $perguntas = [];

        foreach (self::SECOES as $secao) {
            foreach ($secao['perguntas'] as $pergunta) {
                $perguntas[] = [...$pergunta, 'secao' => $secao['chave']];
            }
        }

        return $perguntas;
    }

    /** @return list<string> */
    public static function chaves(): array
    {
        return array_column(self::perguntas(), 'chave');
    }

    /**
     * A nota final: a soma ponderada das respostas, de 0 a 10.
     *
     * Pergunta não respondida vale zero — é o mesmo tratamento da rubrica
     * online, e o envio só é aceito com tudo respondido.
     *
     * @param  array<string, mixed>  $respostas
     */
    public static function nota(array $respostas): float
    {
        $total = 0.0;

        foreach (self::perguntas() as $pergunta) {
            $total += self::pontos($pergunta, $respostas[$pergunta['chave']] ?? null);
        }

        return round($total, 2);
    }

    /**
     * Quanto vale, no máximo, uma seção.
     */
    public static function maximoDaSecao(string $chave): float
    {
        return round(array_sum(array_map(
            fn (array $p) => $p['secao'] === $chave ? $p['peso'] : 0.0,
            self::perguntas(),
        )), 4);
    }

    /**
     * Os pontos que uma seção rendeu nestas respostas.
     *
     * @param  array<string, mixed>  $respostas
     */
    public static function pontosDaSecao(string $chave, array $respostas): float
    {
        $total = 0.0;

        foreach (self::perguntas() as $pergunta) {
            if ($pergunta['secao'] === $chave) {
                $total += self::pontos($pergunta, $respostas[$pergunta['chave']] ?? null);
            }
        }

        return round($total, 2);
    }

    /**
     * A rubrica como a API a entrega ao front.
     *
     * @return array<string, mixed>
     */
    public static function paraApi(): array
    {
        return [
            'nota_maxima' => self::NOTA_MAXIMA,
            'escala' => array_map(
                fn (int $valor, string $rotulo) => ['valor' => $valor, 'rotulo' => $rotulo],
                array_keys(self::ESCALA),
                array_values(self::ESCALA),
            ),
            'secoes' => array_map(fn (array $secao) => [
                ...$secao,
                'maximo' => self::maximoDaSecao($secao['chave']),
                'perguntas' => array_map(fn (array $p) => [...$p, 'peso' => round($p['peso'], 4)], $secao['perguntas']),
            ], self::SECOES),
        ];
    }

    /**
     * Pontos de uma resposta: a fração da escala multiplicada pelo peso.
     *
     * @param  array<string, mixed>  $pergunta
     */
    private static function pontos(array $pergunta, mixed $resposta): float
    {
        if ($resposta === null || $resposta === '') {
            return 0.0;
        }

        return ((int) $resposta / max(array_keys(self::ESCALA))) * $pergunta['peso'];
    }
}
