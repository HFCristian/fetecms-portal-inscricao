<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Alunos por classe escolar (painel do admin).
 *
 * O formulário do aluno grava duas colunas: `modalidade` (fundamental_i,
 * fundamental_ii, medio, tecnico_integrado) e `ano_escolar` (3_ef…9_ef,
 * 1_em…4_em). O painel resume isso em **três cards** — Ensino Fundamental I,
 * Ensino Fundamental II e Ensino Médio —, cada um com a quebra por série.
 *
 * O **Técnico Integrado entra no card de Ensino Médio**: é ensino médio na
 * prática, e os códigos de série são os mesmos (só ele chega ao 4º ano).
 *
 * Só as séries conhecidas são listadas: quem está sem série (ou com um código
 * de cadastro antigo, fora da lista) **não aparece** na quebra, então a soma das
 * séries pode ser menor que o número grande do card — que continua sendo o total
 * de alunos da classe. Aluno sem modalidade nenhuma não entra em card algum:
 * não há como adivinhar a classe dele.
 */
class ClassesEscolares
{
    /**
     * As três classes do painel: rótulo, modalidades que caem nela e as séries
     * que ela exibe, na ordem.
     *
     * @var array<string, array{label: string, modalidades: list<string>, series: array<string, string>}>
     */
    public const CLASSES = [
        'fundamental_i' => [
            'label' => 'Ensino Fundamental I',
            'modalidades' => ['fundamental_i'],
            'series' => [
                '3_ef' => '3º ano',
                '4_ef' => '4º ano',
                '5_ef' => '5º ano',
            ],
        ],
        'fundamental_ii' => [
            'label' => 'Ensino Fundamental II',
            'modalidades' => ['fundamental_ii'],
            'series' => [
                '6_ef' => '6º ano',
                '7_ef' => '7º ano',
                '8_ef' => '8º ano',
                '9_ef' => '9º ano',
            ],
        ],
        'medio' => [
            'label' => 'Ensino Médio',
            // O técnico integrado é contado aqui, junto do médio regular.
            'modalidades' => ['medio', 'tecnico_integrado'],
            'series' => [
                '1_em' => '1º ano',
                '2_em' => '2º ano',
                '3_em' => '3º ano',
                '4_em' => '4º ano',
            ],
        ],
    ];

    /**
     * Um bloco por classe, na ordem acima, mesmo as zeradas.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query  já filtrada (tabela `alunos`)
     * @return list<array{chave:string, label:string, total:int, series: list<array{serie:string, total:int}>}>
     */
    public static function contar(Builder $query): array
    {
        // Uma consulta só: (modalidade, ano_escolar) → quantos.
        $brutos = (clone $query)
            ->groupBy('modalidade', 'ano_escolar')
            ->selectRaw('modalidade, ano_escolar, count(*) as total')
            ->get();

        return array_values(array_map(function (array $classe, string $chave) use ($brutos) {
            $daClasse = $brutos->filter(
                fn ($linha) => in_array((string) $linha->modalidade, $classe['modalidades'], true),
            );

            $total = (int) $daClasse->sum('total');
            $porSerie = $daClasse->groupBy('ano_escolar')->map(fn ($g) => (int) $g->sum('total'));

            $series = array_map(fn (string $label, string $codigo) => [
                'serie' => $label,
                'total' => $porSerie[$codigo] ?? 0,
            ], $classe['series'], array_keys($classe['series']));

            return [
                'chave' => $chave,
                'label' => $classe['label'],
                'total' => $total,
                'series' => $series,
            ];
        }, self::CLASSES, array_keys(self::CLASSES)));
    }
}
