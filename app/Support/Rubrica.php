<?php

namespace App\Support;

/**
 * Rubrica de avaliação da FETECMS — transcrição do documento "Perguntas de
 * Avaliação FETECMS" entregue pela organização. É a fonte única: o backend
 * valida e calcula por ela, e o front só desenha o que a API manda.
 *
 * Cada pergunta vale de 0 a 1 do seu `peso` (a fração vem da escala, ou de
 * Sim/Não), e a nota final é a soma ponderada — 10,00 no total. As duas
 * seções sem pergunta pontuada existem assim mesmo:
 *
 * - `geral_inicio`: a conferência de área/subárea (vale 0 no documento) já é
 *   feita pelos campos `area_correta`/`subarea_correta` da avaliação;
 * - `final`: os campos descritivos de recomendações (não valem ponto).
 *
 * A seção de Resultados e discussão usa 1/3 por pergunta, e não os 0,33 da
 * coluna do documento, para a seção fechar em 1,00 e o total em 10,00 —
 * como manda a tabela de fechamento da última página.
 */
final class Rubrica
{
    /** Pergunta pontuada pela escala de 0 a 10 do documento. */
    public const TIPO_ESCALA = 'escala';

    /** Pergunta de Sim (peso cheio) ou Não (zero). */
    public const TIPO_SIM_NAO = 'sim_nao';

    /** Escala das perguntas "de que modo…" (valor => rótulo). */
    public const ESCALA = [
        0 => 'Não possui',
        2 => 'Muito ruim',
        4 => 'Ruim',
        6 => 'Regular',
        8 => 'Bom',
        10 => 'Muito bom',
    ];

    /** Nota máxima da avaliação — a soma dos pesos. */
    public const NOTA_MAXIMA = 10.0;

    /**
     * Seções na ordem em que o avaliador as percorre. `componente` diz o que o
     * front desenha no passo: as perguntas pontuadas, a conferência da
     * classificação ou só os campos de recomendação.
     */
    public const SECOES = [
        [
            'chave' => 'geral_inicio',
            'titulo' => 'Geral — início',
            'icone' => 'category',
            'componente' => 'classificacao',
            'ajuda' => 'Avalie a escolha da área e, caso necessário, faça a sugestão de adequação.',
            'perguntas' => [],
        ],
        [
            'chave' => 'titulo',
            'titulo' => 'Título',
            'icone' => 'title',
            'componente' => 'perguntas',
            'perguntas' => [
                [
                    'chave' => 'titulo_coerente',
                    'rotulo' => 'coerência do título',
                    'texto' => 'O título do projeto é coerente ao trabalho descrito, sendo sucinto e despertando interesse na leitura?',
                    'ajuda' => 'Avalie o título do projeto, levando em consideração sua adequação e adesão ao que foi proposto.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.15,
                ],
            ],
        ],
        [
            'chave' => 'resumo',
            'titulo' => 'Resumo',
            'icone' => 'description',
            'componente' => 'perguntas',
            'perguntas' => [
                [
                    'chave' => 'resumo_sintese',
                    'rotulo' => 'síntese do resumo',
                    'texto' => 'De que modo o resumo consegue sintetizar o projeto?',
                    'ajuda' => 'Avalie se o resumo é coerente com o projeto de pesquisa. Observe se os elementos principais do projeto são apresentados de forma coerente.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 0.125,
                ],
                [
                    'chave' => 'palavras_chave',
                    'rotulo' => 'palavras-chave',
                    'texto' => 'As palavras-chave estão de acordo com o teor do projeto?',
                    'ajuda' => 'Avalie as palavras-chave do projeto, levando em consideração sua adequação e adesão ao que foi proposto.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.125,
                ],
            ],
        ],
        [
            'chave' => 'introducao',
            'titulo' => 'Introdução',
            'icone' => 'menu_book',
            'componente' => 'perguntas',
            'perguntas' => [
                [
                    'chave' => 'introducao_problema',
                    'rotulo' => 'apresentação do problema',
                    'texto' => 'De que modo o problema/pergunta é apresentado no projeto?',
                    'ajuda' => 'Avalie a apresentação do problema/pergunta, levando em consideração seu grau de exposição e de coesão.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 1.075,
                ],
            ],
        ],
        [
            'chave' => 'objetivos',
            'titulo' => 'Objetivos',
            'icone' => 'flag',
            'componente' => 'perguntas',
            'perguntas' => [
                [
                    'chave' => 'objetivo_correlacao',
                    'rotulo' => 'correlação do objetivo com o problema',
                    'texto' => 'De que modo o objetivo se correlaciona com o problema/pergunta apresentado no projeto?',
                    'ajuda' => 'Avalie a clareza e coerência dos objetivos estabelecidos com base no problema/pergunta apresentado na introdução do projeto.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 0.5375,
                ],
                [
                    'chave' => 'objetivos_demonstracao',
                    'rotulo' => 'demonstração dos objetivos',
                    'texto' => 'De que modo os objetivos estão demonstrados no projeto? Leve em consideração a construção lógica e a formatação',
                    'ajuda' => 'Avalie a clareza e objetividade da escrita, bem como o máximo de 5 objetivos específicos.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 0.5375,
                ],
            ],
        ],
        [
            'chave' => 'metodologia',
            'titulo' => 'Metodologia',
            'icone' => 'science',
            'componente' => 'perguntas',
            'perguntas' => [
                [
                    'chave' => 'metodo_adequacao',
                    'rotulo' => 'adequação do método',
                    'texto' => 'De que modo o método é adequado para atingir os objetivos propostos pelo projeto?',
                    'ajuda' => 'Avalie se a metodologia é apropriada aos objetivos previamente estabelecidos.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 0.775,
                ],
                [
                    'chave' => 'metodologia_clareza',
                    'rotulo' => 'clareza da metodologia',
                    'texto' => 'De que modo a metodologia apresenta clareza e descrição das etapas desenvolvidas no projeto?',
                    'ajuda' => 'Avalie a clareza e nível descritivo da metodologia.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 0.775,
                ],
            ],
        ],
        [
            'chave' => 'resultados',
            'titulo' => 'Resultados e discussão',
            'icone' => 'insights',
            'componente' => 'perguntas',
            'perguntas' => [
                [
                    'chave' => 'resultados_conexao',
                    'rotulo' => 'conexão dos resultados com a metodologia',
                    'texto' => 'De que modo os resultados (ou resultados esperados) estão trabalhados no projeto, de maneira que estabeleçam conexão com a metodologia aplicada?',
                    'ajuda' => 'Avalie se os resultados obtidos e/ou esperados são apropriados ao método aplicado e às perguntas/objetivos previamente estabelecidos.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 1 / 3,
                ],
                [
                    'chave' => 'resultados_apresentacao',
                    'rotulo' => 'apresentação dos resultados',
                    'texto' => 'De que modo os resultados (ou resultados esperados) são apresentados no projeto? Considere clareza, recursos gráficos, objetividade, sequência lógica etc.',
                    'ajuda' => 'Avalie a clareza e coerência na apresentação da metodologia do projeto, levando em consideração seu grau de exposição e de coesão. Avalie, também, o uso de recursos gráficos, com atenção a coerência das imagens com o resultado exposto, bem como as métricas (eixos em gráficos e tabelas).',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 1 / 3,
                ],
                [
                    'chave' => 'resultados_discussao',
                    'rotulo' => 'discussão dos resultados',
                    'texto' => 'De que modo são discutidos os resultados obtidos (ou resultados esperados) no projeto? Leve em consideração à coerência e conexão da discussão com as referências apresentadas',
                    'ajuda' => 'Considere se a discussão trata de todos os resultados apresentados e se os relaciona com dados existentes nas referências e/ou suas implicações. Observe se, quando presente, os resultados negativos também são considerados e expostos.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 1 / 3,
                ],
            ],
        ],
        [
            'chave' => 'conclusao',
            'titulo' => 'Conclusão',
            'icone' => 'task_alt',
            'componente' => 'perguntas',
            'perguntas' => [
                [
                    'chave' => 'conclusao_correlacao',
                    'rotulo' => 'correlação da conclusão',
                    'texto' => 'De que modo a conclusão se correlaciona com os demais tópicos e realiza um encerramento do projeto?',
                    'ajuda' => 'Avalie o poder de síntese do texto, além da clareza e coerência, levando em consideração seu grau de exposição e de coesão com o projeto.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 1.1,
                ],
            ],
        ],
        [
            'chave' => 'referencias',
            'titulo' => 'Referências',
            'icone' => 'format_quote',
            'componente' => 'perguntas',
            'perguntas' => [
                [
                    'chave' => 'referencias_qualidade',
                    'rotulo' => 'qualidade das referências',
                    'texto' => 'As referências bibliográficas citadas estão trabalhadas de que forma? Leve em consideração a qualidade da fonte da referência; a atualidade/relevância da referência; Aderência ao área de pesquisa do projeto.',
                    'ajuda' => 'Avalie se as referências utilizadas foram obtidas de fontes confiáveis e de cunho técnico científico e/ou textos não científicos (por exemplo: foram utilizados artigos científicos, blogs, livros?)',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 1.0,
                ],
            ],
        ],
        [
            'chave' => 'geral_projeto',
            'titulo' => 'Geral — projeto',
            'icone' => 'fact_check',
            'componente' => 'perguntas',
            'perguntas' => [
                [
                    'chave' => 'formatacao_abnt',
                    'rotulo' => 'formatação e ortografia',
                    'texto' => 'Como pode ser classificada a formatação e ortografia do projeto? Leve em consideração as normas da ABNT',
                    'ajuda' => 'Avalie se o projeto de pesquisa utiliza o modelo disponibilizado pela FETECMS. Observe, também, se a estrutura textual está formatada adequadamente e se as citações utilizadas no texto estão presentes nas referências bibliográficas.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 0.4,
                ],
                [
                    'chave' => 'criatividade',
                    'rotulo' => 'criatividade',
                    'texto' => 'Avalie a aplicação da criatividade no desenvolvimento do projeto de pesquisa',
                    'ajuda' => 'Avalie considerando o desempenho dos alunos no desenvolvimento de perguntas, elaboração de soluções criativas e suposições ao longo da pesquisa',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 0.4,
                ],
            ],
        ],
        [
            'chave' => 'video',
            'titulo' => 'Vídeo',
            'icone' => 'movie',
            'componente' => 'perguntas',
            // Recomendações sobre o vídeo: ficam junto das perguntas do vídeo,
            // enquanto o avaliador ainda está com a apresentação na cabeça.
            'comentario' => [
                'campo' => 'comentario_video',
                'label' => 'Recomendações, dicas e comentários sobre o vídeo',
                'placeholder' => 'O que a equipe pode melhorar na apresentação em vídeo?',
            ],
            'perguntas' => [
                [
                    'chave' => 'video_engajamento',
                    'rotulo' => 'engajamento e criatividade no vídeo',
                    'texto' => 'De que modo o vídeo expressa o engajamento e criatividade dos integrantes, em relação ao projeto, e proporciona uma melhor compreensão do trabalho?',
                    'ajuda' => 'Avaliar clareza e criatividade na apresentação, bem como se os estudantes expõe os elementos: introdução, objetivos, metodologia, resultados e discussão e conclusão de forma resumida e eficiente. Observe, também, se há apenas estudantes da equipe participando da apresentação em vídeo.',
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 1.0,
                ],
                [
                    'chave' => 'video_dominio',
                    'rotulo' => 'domínio do tema no vídeo',
                    'texto' => 'A partir do vídeo, de que modo os integrantes da equipe demonstram domínio do tema?',
                    // O documento ainda não traz a orientação desta pergunta
                    // ("????"): sem texto, o front não desenha o balão de ajuda.
                    'ajuda' => null,
                    'tipo' => self::TIPO_ESCALA,
                    'peso' => 1.0,
                ],
            ],
        ],
        [
            'chave' => 'final',
            'titulo' => 'Final',
            'icone' => 'rate_review',
            'componente' => 'comentarios',
            'ajuda' => 'Considere os pontos fortes do projeto e escreva sobre possíveis melhorias e ideias para o prosseguimento da pesquisa.',
            'comentario' => [
                'campo' => 'comentario_projeto',
                'label' => 'De modo geral, para o projeto, faça recomendações, dicas, sugestões, comentários etc',
                'placeholder' => 'Pontos fortes, melhorias possíveis e ideias para o prosseguimento da pesquisa.',
            ],
            'perguntas' => [],
        ],
    ];

    /** Campos descritivos da avaliação (não valem ponto). */
    public const COMENTARIOS = ['comentario_video', 'comentario_projeto'];

    /**
     * Todas as perguntas pontuadas, na ordem, já com a seção de cada uma.
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

    /**
     * Uma pergunta pela chave (null se a chave não existir na rubrica).
     *
     * @return array<string, mixed>|null
     */
    public static function pergunta(string $chave): ?array
    {
        foreach (self::perguntas() as $pergunta) {
            if ($pergunta['chave'] === $chave) {
                return $pergunta;
            }
        }

        return null;
    }

    /**
     * Chaves de todas as perguntas pontuadas.
     *
     * @return list<string>
     */
    public static function chaves(): array
    {
        return array_column(self::perguntas(), 'chave');
    }

    /**
     * Respostas prontas para gravar: só as chaves da rubrica, com inteiro nas
     * perguntas de escala e booleano nas de Sim/Não (o JSON do cliente pode
     * chegar com "1"/"0"). Resposta vazia é descartada — no rascunho a
     * pergunta simplesmente segue sem resposta.
     *
     * @param  array<string, mixed>  $respostas
     * @return array<string, int|bool>
     */
    public static function normalizar(array $respostas): array
    {
        $limpas = [];

        foreach (self::perguntas() as $pergunta) {
            $valor = $respostas[$pergunta['chave']] ?? null;

            if ($valor === null || $valor === '') {
                continue;
            }

            $limpas[$pergunta['chave']] = $pergunta['tipo'] === self::TIPO_SIM_NAO
                ? filter_var($valor, FILTER_VALIDATE_BOOLEAN)
                : (int) $valor;
        }

        return $limpas;
    }

    /**
     * Nota ponderada das respostas, de 0 a 10 — o valor gravado em `nota` ao
     * concluir. Pergunta sem resposta simplesmente não pontua, o que também dá
     * a nota parcial de um rascunho.
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
     * Quanto uma seção rendeu nestas respostas (0 até o máximo da seção).
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

        return round($total, 4);
    }

    /** Soma dos pesos de uma seção — o teto dela (1,075 na Introdução, por exemplo). */
    public static function maximoDaSecao(string $chave): float
    {
        $pesos = array_map(
            fn (array $p) => $p['secao'] === $chave ? $p['peso'] : 0.0,
            self::perguntas(),
        );

        return round(array_sum($pesos), 4);
    }

    /**
     * Seções que têm ao menos uma pergunta pontuada, com o teto de cada uma —
     * a base das médias por seção no ranking do admin.
     *
     * @return list<array{chave:string, titulo:string, maximo:float}>
     */
    public static function secoesPontuadas(): array
    {
        $secoes = [];

        foreach (self::SECOES as $secao) {
            if ($secao['perguntas'] !== []) {
                $secoes[] = [
                    'chave' => $secao['chave'],
                    'titulo' => $secao['titulo'],
                    'maximo' => self::maximoDaSecao($secao['chave']),
                ];
            }
        }

        return $secoes;
    }

    /**
     * A rubrica como a API a entrega ao front: escala, seções e pesos já
     * arredondados para exibição.
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
                'perguntas' => array_map(fn (array $p) => [
                    ...$p,
                    // 4 casas bastam para os pesos do documento (0,5375) e para 1/3.
                    'peso' => round($p['peso'], 4),
                ], $secao['perguntas']),
            ], self::SECOES),
        ];
    }

    /**
     * Pontos de uma resposta: a fração da escala (ou 1 no "Sim", 0 no "Não")
     * multiplicada pelo peso da pergunta.
     *
     * @param  array<string, mixed>  $pergunta
     */
    private static function pontos(array $pergunta, mixed $resposta): float
    {
        if ($resposta === null || $resposta === '') {
            return 0.0;
        }

        if ($pergunta['tipo'] === self::TIPO_SIM_NAO) {
            return $resposta ? (float) $pergunta['peso'] : 0.0;
        }

        return ((int) $resposta / max(array_keys(self::ESCALA))) * $pergunta['peso'];
    }
}
