<?php

namespace App\Support;

/**
 * Conjuntos de alternativas prontos, oferecidos ao admin no formulário de
 * feedback para ele não redigir a mesma escala toda vez.
 *
 * O modelo é só um atalho de digitação: as opções são **copiadas** para a
 * pergunta no momento da criação. Mexer aqui depois não altera pedido nenhum já
 * criado — o que é o que se quer, porque mudar a escala embaixo de respostas já
 * dadas as tornaria incomparáveis.
 */
class ModelosAlternativas
{
    /** @var array<string, array{label: string, opcoes: list<string>}> */
    public const MODELOS = [
        'satisfacao' => [
            'label' => 'Satisfação (5 pontos)',
            'opcoes' => ['Muito insatisfeito', 'Insatisfeito', 'Neutro', 'Satisfeito', 'Muito satisfeito'],
        ],
        'concordancia' => [
            'label' => 'Concordância (5 pontos)',
            'opcoes' => ['Discordo totalmente', 'Discordo', 'Neutro', 'Concordo', 'Concordo totalmente'],
        ],
        'frequencia' => [
            'label' => 'Frequência (5 pontos)',
            'opcoes' => ['Nunca', 'Raramente', 'Às vezes', 'Frequentemente', 'Sempre'],
        ],
        'sim_nao' => [
            'label' => 'Sim / Não',
            'opcoes' => ['Sim', 'Não'],
        ],
        'nps' => [
            'label' => 'Nota de 0 a 10 (NPS)',
            'opcoes' => ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10'],
        ],
    ];

    /** @return array<int, array{value: string, label: string, opcoes: list<string>}> */
    public static function opcoes(): array
    {
        return array_values(array_map(
            fn (string $chave, array $m) => [
                'value' => $chave,
                'label' => $m['label'],
                'opcoes' => $m['opcoes'],
            ],
            array_keys(self::MODELOS),
            self::MODELOS,
        ));
    }

    /** @return list<string> */
    public static function opcoesDe(string $chave): array
    {
        return self::MODELOS[$chave]['opcoes'] ?? [];
    }
}
