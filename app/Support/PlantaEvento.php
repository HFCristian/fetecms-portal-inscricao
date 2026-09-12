<?php

namespace App\Support;

/**
 * A planta do ginásio: onde fica cada estande no desenho.
 *
 * O layout **padrão** é o projeto da XVI FETECMS (Ginásio Moreninho/UFMS,
 * prancha "Implantação" de 03.08.26): 230 estandes de 1,5 × 1,0 m em dois
 * blocos, cada bloco com ilhas de duas colunas e uma fileira encostada na
 * parede. A numeração serpenteia, e por isso ela é **escrita à mão** aqui em vez
 * de derivada de uma fórmula: a prancha não segue uma regra única, e inventar
 * uma faria o mapa do portal discordar do mapa impresso que está colado na
 * parede do evento.
 *
 * As coordenadas são em **unidades de grade** (1 = um estande), não em metros:
 * quem desenha é o SVG da tela, que escala conforme o tamanho disponível. O eixo
 * y cresce para baixo, como no SVG.
 *
 * Este é só o **ponto de partida**. O admin edita a planta na tela e cada
 * gravação cria uma **versão nova** (`mapa_layouts`), então o ginásio do ano que
 * vem não obriga ninguém a mexer em código — e o desenho de cada edição fica
 * guardado.
 */
final class PlantaEvento
{
    /** Linhas de cada bloco (o primeiro e o último par de ilhas têm 5). */
    private const LINHAS = 7;

    /** Onde o bloco de baixo começa, deixando o corredor central no meio. */
    private const Y_BLOCO_INFERIOR = 9;

    /**
     * O layout de fábrica, pronto para ser gravado como versão 1 de uma edição.
     *
     * @return array<string, mixed>
     */
    public static function padrao(): array
    {
        $estandes = array_merge(self::blocoSuperior(), self::blocoInferior());

        return self::normalizar([
            'nome' => 'Planta padrão — Ginásio Moreninho (UFMS)',
            'estandes' => $estandes,
            'marcacoes' => [
                ['rotulo' => 'Entrada', 'x' => 12, 'y' => 16.6, 'largura' => 3, 'altura' => 0.8],
            ],
        ]);
    }

    /**
     * Limpa um layout vindo da tela: estandes com número inteiro positivo e
     * posição numérica, **sem número repetido** — dois estandes com o mesmo
     * número fariam a busca do mapa devolver dois lugares para o mesmo projeto.
     *
     * @param  array<string, mixed>  $bruto
     * @return array<string, mixed>
     */
    public static function normalizar(array $bruto): array
    {
        $vistos = [];
        $estandes = [];

        foreach ((array) ($bruto['estandes'] ?? []) as $estande) {
            if (! is_array($estande)) {
                continue;
            }

            $numero = (int) ($estande['numero'] ?? 0);

            if ($numero < 1 || isset($vistos[$numero])) {
                continue;
            }

            $vistos[$numero] = true;

            $estandes[] = [
                'numero' => $numero,
                'x' => round((float) ($estande['x'] ?? 0), 2),
                'y' => round((float) ($estande['y'] ?? 0), 2),
            ];
        }

        usort($estandes, fn (array $a, array $b) => $a['numero'] <=> $b['numero']);

        $marcacoes = [];

        foreach ((array) ($bruto['marcacoes'] ?? []) as $marcacao) {
            if (! is_array($marcacao) || trim((string) ($marcacao['rotulo'] ?? '')) === '') {
                continue;
            }

            $marcacoes[] = [
                'rotulo' => trim((string) $marcacao['rotulo']),
                'x' => round((float) ($marcacao['x'] ?? 0), 2),
                'y' => round((float) ($marcacao['y'] ?? 0), 2),
                'largura' => max(0.5, round((float) ($marcacao['largura'] ?? 1), 2)),
                'altura' => max(0.5, round((float) ($marcacao['altura'] ?? 1), 2)),
            ];
        }

        return [
            'nome' => trim((string) ($bruto['nome'] ?? '')) ?: 'Planta do evento',
            'estandes' => $estandes,
            'marcacoes' => $marcacoes,
        ];
    }

    /**
     * Bloco de cima: a ilha da ponta com 5 linhas, sete ilhas de 7 e a fileira
     * encostada na parede direita (109–115).
     *
     * @return list<array{numero:int, x:float, y:float}>
     */
    private static function blocoSuperior(): array
    {
        $estandes = [];

        // Ilha da ponta esquerda: 001–005 descendo, 010–006 do lado.
        $estandes = array_merge(
            $estandes,
            self::coluna([1, 2, 3, 4, 5], 0, 0),
            self::coluna([10, 9, 8, 7, 6], 1, 0),
        );

        // As sete ilhas seguintes: a coluna da esquerda sobe a numeração, a da
        // direita desce — é o vaivém da prancha.
        $numero = 11;

        for ($i = 0; $i < 7; $i++) {
            $x = 3 + $i * 3;
            $esquerda = range($numero, $numero + self::LINHAS - 1);
            $direita = range($numero + 2 * self::LINHAS - 1, $numero + self::LINHAS);

            $estandes = array_merge(
                $estandes,
                self::coluna($esquerda, $x, 0),
                self::coluna($direita, $x + 1, 0),
            );

            $numero += 2 * self::LINHAS;
        }

        // A fileira encostada na parede: uma coluna só.
        return array_merge($estandes, self::coluna(range(109, 115), 24, 0));
    }

    /**
     * Bloco de baixo, lido da parede para dentro: 116–122 na parede e as ilhas
     * crescendo para a esquerda até 230.
     *
     * @return list<array{numero:int, x:float, y:float}>
     */
    private static function blocoInferior(): array
    {
        $y = self::Y_BLOCO_INFERIOR;

        $estandes = self::coluna(range(116, 122), 24, $y);

        // Primeira ilha depois da parede: as duas colunas descem a numeração.
        $estandes = array_merge(
            $estandes,
            self::coluna(range(130, 136), 21, $y),
            self::coluna(range(123, 129), 22, $y),
        );

        // As seis seguintes: coluna da direita subindo, da esquerda descendo.
        $numero = 137;

        for ($i = 0; $i < 6; $i++) {
            $x = 18 - $i * 3;
            $direita = range($numero, $numero + self::LINHAS - 1);
            $esquerda = range($numero + 2 * self::LINHAS - 1, $numero + self::LINHAS);

            $estandes = array_merge(
                $estandes,
                self::coluna($esquerda, $x, $y),
                self::coluna($direita, $x + 1, $y),
            );

            $numero += 2 * self::LINHAS;
        }

        // Ilha da ponta esquerda, com 5 linhas, alinhada pela base do bloco.
        return array_merge(
            $estandes,
            self::coluna([230, 229, 228, 227, 226], 0, $y + 2),
            self::coluna([221, 222, 223, 224, 225], 1, $y + 2),
        );
    }

    /**
     * Uma coluna de estandes, do topo para baixo.
     *
     * @param  list<int>  $numeros
     * @return list<array{numero:int, x:float, y:float}>
     */
    private static function coluna(array $numeros, float $x, float $y): array
    {
        $coluna = [];

        foreach (array_values($numeros) as $linha => $numero) {
            $coluna[] = ['numero' => (int) $numero, 'x' => $x, 'y' => $y + $linha];
        }

        return $coluna;
    }
}
