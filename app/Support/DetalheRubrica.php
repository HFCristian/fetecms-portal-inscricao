<?php

namespace App\Support;

/**
 * A leitura de uma avaliação **por dentro**: cada seção da rubrica oficial com
 * os pontos que rendeu, e cada pergunta com a resposta em palavras.
 *
 * É Support, e não Service, porque não decide nada e não toca no banco: recebe
 * as respostas gravadas e devolve a mesma coisa que o avaliador leu, somada.
 * Mora aqui porque três caminhos precisam dela e nenhum é dono — Designações →
 * Ver notas, a comparação por projeto da tela de disparidade e, através dela, a
 * conferência de uma nota antes de desconsiderá-la.
 */
class DetalheRubrica
{
    /**
     * As seções pontuadas, com as perguntas de cada uma.
     *
     * @param  array<string, mixed>  $respostas
     * @return list<array<string, mixed>>
     */
    public static function secoes(array $respostas): array
    {
        return array_map(function (array $secao) use ($respostas) {
            $perguntas = array_values(array_filter(
                Rubrica::perguntas(),
                fn (array $p) => $p['secao'] === $secao['chave'],
            ));

            return [
                'chave' => $secao['chave'],
                'titulo' => $secao['titulo'],
                'pontos' => round(Rubrica::pontosDaSecao($secao['chave'], $respostas), 2),
                'maximo' => round($secao['maximo'], 2),
                'perguntas' => array_map(
                    fn (array $p) => self::pergunta($p, $respostas[$p['chave']] ?? null),
                    $perguntas,
                ),
            ];
        }, Rubrica::secoesPontuadas());
    }

    /**
     * Uma linha do detalhe: o que foi perguntado, o que o avaliador respondeu e
     * quanto aquilo rendeu.
     *
     * A resposta sai com o **rótulo** da escala ("Bom"), e não só o número: é
     * assim que ela aparece para quem avaliou, e quem lê depois precisa ler a
     * mesma coisa que ele leu.
     *
     * @param  array<string, mixed>  $pergunta
     * @return array<string, mixed>
     */
    public static function pergunta(array $pergunta, mixed $resposta): array
    {
        $peso = round((float) $pergunta['peso'], 4);

        if ($pergunta['tipo'] === Rubrica::TIPO_SIM_NAO) {
            $rotulo = $resposta === null ? null : ($resposta ? 'Sim' : 'Não');
            $pontos = $resposta ? $peso : 0.0;
        } else {
            $valor = $resposta === null || $resposta === '' ? null : (int) $resposta;
            $rotulo = $valor === null ? null : ($valor.' — '.(Rubrica::ESCALA[$valor] ?? '—'));
            $pontos = $valor === null ? 0.0 : ($valor / max(array_keys(Rubrica::ESCALA))) * $peso;
        }

        return [
            'chave' => $pergunta['chave'],
            // `rotulo` é o nome curto do quesito e `texto` é a pergunta inteira:
            // os dois vão, porque a tela usa um como título e o outro como corpo.
            'rotulo' => $pergunta['rotulo'] ?? $pergunta['chave'],
            'pergunta' => $pergunta['texto'] ?? $pergunta['rotulo'] ?? $pergunta['chave'],
            'tipo' => $pergunta['tipo'],
            // Sem resposta é diferente de resposta zero: a tela precisa dizer
            // "não respondida" em vez de fingir um "Não possui".
            'resposta' => $rotulo,
            'respondida' => $rotulo !== null,
            'peso' => $peso,
            'pontos' => round($pontos, 2),
        ];
    }
}
