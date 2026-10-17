<?php

namespace App\Support;

/**
 * Rubrica da **avaliação presencial** — transcrição do documento "Perguntas de
 * Avaliação FETECMS 2026 · Perguntas Etapa Presencial" entregue pela
 * organização. É a fonte única: o backend valida e calcula por ela, e o front
 * só desenha o que a API manda.
 *
 * A nota é **separada** da online: ela não entra no ranking que definiu a lista
 * final (já fechado quando o evento começa) e serve à **premiação**.
 *
 * O documento divide os 10,000 pontos em dois blocos:
 *
 * - **Avaliação (8,500)** — as perguntas "de que modo…", em escala de 0 a 10 de
 *   dois em dois. O rótulo do zero muda com a pergunta ("Não demonstra", "Não
 *   apresentou", "Não possui"), por isso cada pergunta leva as suas opções;
 * - **Documentos e uso de IA (1,500)** — as perguntas de Sim/Não.
 *
 * Os pesos têm três casas e nenhum é múltiplo de outro, de propósito: é o que
 * faz dois projetos raramente empatarem. Por isso a nota é guardada com quatro
 * casas (o 20% de 1,137 já é 0,2274) — arredondar para duas desfaria o
 * desempate que o documento desenhou.
 *
 * Três regras do documento não cabem numa soma simples, e moram aqui:
 *
 * - **perguntas condicionais** (`condicao`): só aparecem quando a pergunta de
 *   que dependem foi respondida com "Sim". Quando não se aplicam, valem uma
 *   fração fixa — zero na 3.2, 4.2 e 4.3 (sem o diário ou sem a pasta não há o
 *   que avaliar), e o peso cheio na 14.2 (só perde ponto quem usou IA sem
 *   declarar);
 * - **"não se aplica"** na 4.3: com as demais referências em QR Code não há o
 *   que destacar, e a resposta vale como Sim;
 * - **perguntas sem valor** (peso 0): os documentos de ética (1.1 e 1.2) não
 *   entram na nota — a 1.2 fica registrada e, quando é "Não", **sinalizada** à
 *   Comissão Organizadora (`sinaliza`); a 14.1 só decide se a 14.2 se aplica.
 */
final class RubricaPresencial
{
    /** Pergunta pontuada pela escala de 0 a 10. */
    public const TIPO_ESCALA = 'escala';

    /** Pergunta de Sim (peso cheio) ou Não (zero). */
    public const TIPO_SIM_NAO = 'sim_nao';

    public const SIM = 'sim';

    public const NAO = 'nao';

    /** Terceira opção da 4.3: vale como Sim. */
    public const NAO_SE_APLICA = 'nao_se_aplica';

    /** Os dois blocos da nota, com o teto de cada um no documento. */
    public const BLOCOS = [
        'avaliacao' => 'Avaliação',
        'documentos' => 'Documentos e uso de IA',
    ];

    /** Nota máxima — a soma dos pesos. */
    public const NOTA_MAXIMA = 10.0;

    /** Casas em que a nota é guardada (ver o comentário da classe). */
    public const CASAS = 4;

    /** Pontos da escala acima do zero — o zero tem rótulo próprio por pergunta. */
    private const ESCALA = [
        2 => 'Muito ruim',
        4 => 'Ruim',
        6 => 'Regular',
        8 => 'Bom',
        10 => 'Muito bom',
    ];

    /**
     * As seções, na ordem do documento — que é a ordem em que o avaliador as
     * percorre no estande: primeiro o material, depois a equipe.
     */
    public const SECOES = [
        [
            'chave' => 'documentos',
            'titulo' => 'Documentos',
            'icone' => 'gavel',
            'ajuda' => 'Os documentos de ética não entram na nota: a resposta fica registrada para a Comissão Organizadora.',
            'perguntas' => [
                [
                    'chave' => 'etica_necessaria',
                    'numero' => '1.1',
                    'texto' => 'O projeto necessita de Termo de Comitê de Ética (TCE) ou Termo de Consentimento/Assentimento Livre e Esclarecido (TCLE/TALE) para sua execução?',
                    'ajuda' => 'Considere se o projeto envolve pesquisa com seres humanos ou animais.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.0,
                    'sem_valor' => 'Define se a 1.2 se aplica.',
                ],
                [
                    'chave' => 'etica_documento',
                    'numero' => '1.2',
                    'texto' => 'Para TCE: o documento está presente no estande? Para TCLE/TALE: foi demonstrado seu uso na metodologia?',
                    'ajuda' => 'Caso não tenha ambos ou falte um, a resposta é NÃO. A resposta é registrada e sinalizada à Comissão Organizadora. Não recolha o documento.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.0,
                    'sem_valor' => 'Fica registrada para a Comissão Organizadora.',
                    // Sem valor também quando não se aplica: não há o que somar.
                    'condicao' => ['pergunta' => 'etica_necessaria', 'valor' => self::SIM, 'fracao_senao' => 0.0],
                    'sinaliza' => ['valor' => self::NAO, 'texto' => 'Ética: TCE/TCLE/TALE exigido e não apresentado'],
                ],
            ],
        ],
        [
            'chave' => 'projeto_pesquisa',
            'titulo' => 'Projeto de Pesquisa',
            'icone' => 'description',
            'perguntas' => [
                [
                    'chave' => 'projeto_impresso',
                    'numero' => '2.1',
                    'texto' => 'A equipe detém o projeto de pesquisa impresso?',
                    'ajuda' => 'Verifique apenas se o projeto impresso está disponível no estande.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.283,
                ],
                [
                    'chave' => 'projeto_abnt',
                    'numero' => '2.2',
                    'texto' => 'O projeto de pesquisa está adequado conforme as normas da ABNT?',
                    'ajuda' => 'Observe a formatação geral, o uso do modelo da FETECMS 2026 e se as citações do texto estão nas referências.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.127,
                ],
            ],
        ],
        [
            'chave' => 'diario_bordo',
            'titulo' => 'Diário de Bordo',
            'icone' => 'edit_note',
            'perguntas' => [
                [
                    'chave' => 'diario_manuscrito',
                    'numero' => '3.1',
                    'texto' => 'A equipe detém o Diário de Bordo manuscrito?',
                    'ajuda' => 'Verifique se o diário é manuscrito e datado. Diário digitado ou impresso = NÃO.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.263,
                ],
                [
                    'chave' => 'diario_registro',
                    'numero' => '3.2',
                    'texto' => 'De que modo o Diário de Bordo demonstra organização, lógica e criatividade no registro das atividades?',
                    'ajuda' => 'Leve em conta as reflexões, observações e informações registradas pelos alunos e a continuidade do registro das etapas.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não demonstra',
                    'peso' => 0.301,
                    'condicao' => ['pergunta' => 'diario_manuscrito', 'valor' => self::SIM, 'fracao_senao' => 0.0],
                ],
            ],
        ],
        [
            'chave' => 'pasta_referencia',
            'titulo' => 'Pasta de Referência',
            'icone' => 'folder_open',
            'perguntas' => [
                [
                    'chave' => 'pasta_referencias',
                    'numero' => '4.1',
                    'texto' => 'A equipe possui a pasta de referências com as 5 principais referências impressas obrigatórias conforme o edital?',
                    'ajuda' => 'Conte as referências impressas: são necessárias no mínimo 5.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.239,
                ],
                [
                    'chave' => 'referencias_demais',
                    'numero' => '4.2',
                    'texto' => 'As demais referências são disponibilizadas de forma impressa ou via QR Code?',
                    'ajuda' => 'Considere SIM se todas as demais referências estiverem acessíveis, impressas ou por QR Code.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.049,
                    'condicao' => ['pergunta' => 'pasta_referencias', 'valor' => self::SIM, 'fracao_senao' => 0.0],
                ],
                [
                    'chave' => 'referencias_destacadas',
                    'numero' => '4.3',
                    'texto' => 'Caso todas as demais referências estejam impressas, as 5 principais estão destacadas?',
                    'ajuda' => 'Se a equipe usou QR Code para as demais referências, a pergunta não se aplica e vale como SIM.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.029,
                    'nao_se_aplica' => 'Não se aplica (QR Code)',
                    'condicao' => ['pergunta' => 'pasta_referencias', 'valor' => self::SIM, 'fracao_senao' => 0.0],
                ],
                [
                    'chave' => 'referencias_nbr',
                    'numero' => '4.4',
                    'texto' => 'As referências bibliográficas estão em conformidade com a NBR 10520 vigente?',
                    'ajuda' => 'Observe a padronização das referências e citações.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.101,
                ],
            ],
        ],
        [
            'chave' => 'banner',
            'titulo' => 'Banner',
            'icone' => 'image',
            'perguntas' => [
                [
                    'chave' => 'banner_secoes',
                    'numero' => '5.1',
                    'texto' => 'O banner contém introdução, objetivos, metodologia, resultados obtidos/esperados, discussão, conclusão e referências (mínimo 3)?',
                    'ajuda' => 'Faltando qualquer seção ou com menos de 3 referências, a resposta é NÃO.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.173,
                ],
                [
                    'chave' => 'banner_elaboracao',
                    'numero' => '5.2',
                    'texto' => 'De que modo o banner foi elaborado, considerando os pontos desenvolvidos e o uso de imagens e elementos ilustrativos?',
                    'ajuda' => 'Avalie a organização visual, a clareza e a coerência das imagens e gráficos com o conteúdo.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não demonstra',
                    'peso' => 0.443,
                ],
                [
                    'chave' => 'banner_conexao',
                    'numero' => '5.3',
                    'texto' => 'De que modo o banner se conecta com a apresentação oral do projeto?',
                    'ajuda' => 'Avalie se os estudantes usam o banner como apoio e se o conteúdo falado corresponde ao exposto.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não demonstra',
                    'peso' => 0.391,
                ],
                [
                    'chave' => 'banner_normas',
                    'numero' => '5.4',
                    'texto' => 'O banner está adequado conforme as normas da ABNT e a NBR 10520 vigente?',
                    'ajuda' => 'Observe as citações e referências do banner.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.079,
                ],
            ],
        ],
        [
            'chave' => 'apresentacao',
            'titulo' => 'Apresentação',
            'icone' => 'record_voice_over',
            'perguntas' => [
                [
                    'chave' => 'apresentacao',
                    'numero' => '6.1',
                    'texto' => 'De que modo a equipe apresentou o projeto de forma clara, coesa e objetiva?',
                    'ajuda' => 'Avalie a clareza, a sequência lógica e a síntese da apresentação feita pelos estudantes. Apresentação feita por orientador ou terceiros reduz esta nota e deve ser notificada à Comissão Organizadora.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não apresentou',
                    'peso' => 1.137,
                ],
            ],
        ],
        [
            'chave' => 'dominio',
            'titulo' => 'Domínio do Tema',
            'icone' => 'psychology',
            'perguntas' => [
                [
                    'chave' => 'dominio_tema',
                    'numero' => '7.1',
                    'texto' => 'De que modo os integrantes da equipe demonstram domínio do tema?',
                    'ajuda' => 'Considere as respostas às perguntas do avaliador e a segurança dos estudantes ao explicar conceitos e escolhas do projeto.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não apresentou',
                    'peso' => 1.053,
                ],
            ],
        ],
        [
            'chave' => 'problema',
            'titulo' => 'Pergunta/Problema',
            'icone' => 'help',
            'perguntas' => [
                [
                    'chave' => 'problema',
                    'numero' => '8.1',
                    'texto' => 'De que modo a pergunta ou problema de pesquisa ficou clara durante a apresentação?',
                    'ajuda' => 'Avalie se os estudantes conseguem enunciar o problema de forma clara e justificar sua relevância.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não possui',
                    'peso' => 0.803,
                ],
            ],
        ],
        [
            'chave' => 'objetivos',
            'titulo' => 'Objetivos',
            'icone' => 'flag',
            'perguntas' => [
                [
                    'chave' => 'objetivos',
                    'numero' => '9.1',
                    'texto' => 'De que modo os objetivos são alcançáveis e se relacionam com a pergunta/problema apresentada?',
                    'ajuda' => 'Avalie a coerência entre objetivos e problema e se são viáveis para o nível da equipe.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não possui',
                    'peso' => 0.733,
                ],
            ],
        ],
        [
            'chave' => 'metodologia',
            'titulo' => 'Metodologia',
            'icone' => 'science',
            'perguntas' => [
                [
                    'chave' => 'metodologia_adequacao',
                    'numero' => '10.1',
                    'texto' => 'De que modo a metodologia escolhida se mostra adequada para responder ao problema e alcançar os objetivos propostos?',
                    'ajuda' => 'Avalie se o método é apropriado aos objetivos e à pergunta de pesquisa.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não possui',
                    'peso' => 0.947,
                ],
                [
                    'chave' => 'metodologia_execucao',
                    'numero' => '10.2',
                    'texto' => 'O quanto a metodologia foi bem executada pelos alunos durante o desenvolvimento do projeto?',
                    'ajuda' => 'Considere o envolvimento dos estudantes nas etapas, os registros e as evidências da execução.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não possui',
                    'peso' => 0.873,
                ],
            ],
        ],
        [
            'chave' => 'resultados',
            'titulo' => 'Resultados',
            'icone' => 'insights',
            'perguntas' => [
                [
                    'chave' => 'resultados',
                    'numero' => '11.1',
                    'texto' => 'Os resultados demonstrados ou esperados são coerentes com a metodologia e os objetivos apresentados?',
                    'ajuda' => 'Avalie se os resultados, obtidos ou esperados, decorrem do método aplicado e respondem aos objetivos.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não possui',
                    'peso' => 0.611,
                ],
            ],
        ],
        [
            'chave' => 'discussao',
            'titulo' => 'Discussão',
            'icone' => 'forum',
            'perguntas' => [
                [
                    'chave' => 'discussao',
                    'numero' => '12.1',
                    'texto' => 'De que modo a discussão apresenta interpretação coerente com os resultados e conexão com a literatura existente?',
                    'ajuda' => 'Considere se a discussão trata dos resultados apresentados, inclusive os negativos, e se dialoga com as referências.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não possui',
                    'peso' => 0.661,
                ],
            ],
        ],
        [
            'chave' => 'conclusao',
            'titulo' => 'Conclusão',
            'icone' => 'task_alt',
            'perguntas' => [
                [
                    'chave' => 'conclusao',
                    'numero' => '13.1',
                    'texto' => 'De que modo a conclusão se correlaciona com os demais tópicos, realizando um encerramento consistente e apresentando ideias de forma crítica?',
                    'ajuda' => 'Avalie se a conclusão vai além de repetir os resultados e encerra o projeto de forma crítica.',
                    'tipo' => self::TIPO_ESCALA,
                    'zero' => 'Não possui',
                    'peso' => 0.547,
                ],
            ],
        ],
        [
            'chave' => 'uso_ia',
            'titulo' => 'Uso de IA',
            'icone' => 'smart_toy',
            'perguntas' => [
                [
                    'chave' => 'uso_ia',
                    'numero' => '14.1',
                    'texto' => 'O projeto fez uso de Inteligência Artificial Generativa (IAG) em alguma etapa?',
                    'ajuda' => 'Conforme o item 8.5, subitem V, do edital, o uso de IAG deve ser declarado se utilizado em qualquer fase da pesquisa (concepção, redação, análise de dados, submissão). Pergunte à equipe se houve uso e em quais etapas.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.0,
                    'sem_valor' => 'Define se a 14.2 se aplica.',
                ],
                [
                    'chave' => 'uso_ia_declarado',
                    'numero' => '14.2',
                    'texto' => 'Foi sinalizado de forma visual e/ou escrita o uso de IA, expondo quantidade de uso, etapa e como foi utilizada?',
                    'ajuda' => 'O edital exige que a declaração especifique, nos textos e exposições, a ferramenta utilizada e a finalidade (item 8.5, V; Portaria CNPq 2664/2026). A IAG só pode ser usada como ferramenta auxiliar, nunca de forma predominante (item 9.9.3, VI): suspeita de uso predominante deve ser notificada aos monitores.',
                    'tipo' => self::TIPO_SIM_NAO,
                    'peso' => 0.157,
                    // Quem não usou IAG não tem o que declarar: vale como Sim.
                    'condicao' => ['pergunta' => 'uso_ia', 'valor' => self::SIM, 'fracao_senao' => 1.0],
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
     * As respostas possíveis de uma pergunta, com a fração do peso que cada
     * uma rende.
     *
     * @param  array<string, mixed>  $pergunta
     * @return list<array{valor: int|string, rotulo: string, fracao: float}>
     */
    public static function opcoes(array $pergunta): array
    {
        if ($pergunta['tipo'] === self::TIPO_ESCALA) {
            $teto = max(array_keys(self::ESCALA));

            return array_map(
                fn (int $valor, string $rotulo) => ['valor' => $valor, 'rotulo' => $rotulo, 'fracao' => $valor / $teto],
                [0, ...array_keys(self::ESCALA)],
                [$pergunta['zero'] ?? 'Não possui', ...array_values(self::ESCALA)],
            );
        }

        $opcoes = [
            ['valor' => self::SIM, 'rotulo' => 'Sim', 'fracao' => 1.0],
            ['valor' => self::NAO, 'rotulo' => 'Não', 'fracao' => 0.0],
        ];

        if (isset($pergunta['nao_se_aplica'])) {
            $opcoes[] = ['valor' => self::NAO_SE_APLICA, 'rotulo' => $pergunta['nao_se_aplica'], 'fracao' => 1.0];
        }

        return $opcoes;
    }

    /**
     * A pergunta aparece para estas respostas? Sem condição, sempre; com
     * condição, só quando a pergunta de que depende teve a resposta pedida.
     *
     * @param  array<string, mixed>  $pergunta
     * @param  array<string, mixed>  $respostas
     */
    public static function aplicavel(array $pergunta, array $respostas): bool
    {
        $condicao = $pergunta['condicao'] ?? null;

        return $condicao === null || ($respostas[$condicao['pergunta']] ?? null) === $condicao['valor'];
    }

    /**
     * Respostas prontas para gravar: só as chaves da rubrica, só valores que a
     * pergunta aceita, e nada nas perguntas que não se aplicam — trocar a 3.1
     * para "Não" descarta a 3.2 que já estava respondida. Resposta vazia é
     * descartada: no rascunho a pergunta segue sem resposta.
     *
     * As condições apontam sempre para uma pergunta anterior, então percorrer
     * em ordem decide cada uma sobre respostas já limpas.
     *
     * @param  array<string, mixed>  $respostas
     * @return array<string, int|string>
     */
    public static function normalizar(array $respostas): array
    {
        $limpas = [];

        foreach (self::perguntas() as $pergunta) {
            $valor = self::valor($pergunta, $respostas[$pergunta['chave']] ?? null);

            if ($valor !== null && self::aplicavel($pergunta, $limpas)) {
                $limpas[$pergunta['chave']] = $valor;
            }
        }

        return $limpas;
    }

    /**
     * As perguntas que ainda faltam para o envio: as que se aplicam e estão
     * sem resposta. Pergunta sem valor também é obrigatória — é registro.
     *
     * @param  array<string, mixed>  $respostas
     * @return list<string>
     */
    public static function pendentes(array $respostas): array
    {
        $respostas = self::normalizar($respostas);

        return array_values(array_map(
            fn (array $p) => $p['chave'],
            array_filter(
                self::perguntas(),
                fn (array $p) => self::aplicavel($p, $respostas) && ! array_key_exists($p['chave'], $respostas),
            ),
        ));
    }

    /**
     * A nota final: a soma ponderada das respostas, de 0 a 10, com quatro
     * casas. Pergunta sem resposta não pontua, o que também dá a nota parcial
     * de um rascunho.
     *
     * @param  array<string, mixed>  $respostas
     */
    public static function nota(array $respostas): float
    {
        $respostas = self::normalizar($respostas);
        $total = 0.0;

        foreach (self::perguntas() as $pergunta) {
            $total += self::pontos($pergunta, $respostas);
        }

        return round($total, self::CASAS);
    }

    /**
     * Quanto vale, no máximo, uma seção.
     */
    public static function maximoDaSecao(string $chave): float
    {
        return round(array_sum(array_map(
            fn (array $p) => $p['secao'] === $chave ? $p['peso'] : 0.0,
            self::perguntas(),
        )), self::CASAS);
    }

    /**
     * Os pontos que uma seção rendeu nestas respostas.
     *
     * @param  array<string, mixed>  $respostas
     */
    public static function pontosDaSecao(string $chave, array $respostas): float
    {
        $respostas = self::normalizar($respostas);
        $total = 0.0;

        foreach (self::perguntas() as $pergunta) {
            if ($pergunta['secao'] === $chave) {
                $total += self::pontos($pergunta, $respostas);
            }
        }

        return round($total, self::CASAS);
    }

    /**
     * O bloco do documento a que a pergunta pertence: escala é "Avaliação",
     * Sim/Não pontuado é "Documentos e uso de IA". Sem valor, nenhum.
     *
     * @param  array<string, mixed>  $pergunta
     */
    public static function bloco(array $pergunta): ?string
    {
        if ($pergunta['peso'] <= 0) {
            return null;
        }

        return $pergunta['tipo'] === self::TIPO_ESCALA ? 'avaliacao' : 'documentos';
    }

    /** Teto de um bloco: 8,500 na avaliação e 1,500 nos documentos. */
    public static function maximoDoBloco(string $bloco): float
    {
        return round(array_sum(array_map(
            fn (array $p) => self::bloco($p) === $bloco ? $p['peso'] : 0.0,
            self::perguntas(),
        )), self::CASAS);
    }

    /**
     * O que a resposta pede para a Comissão Organizadora ver — hoje, o
     * documento de ética que faltou no estande (1.2 = Não).
     *
     * @param  array<string, mixed>  $respostas
     * @return list<string>
     */
    public static function alertas(array $respostas): array
    {
        $respostas = self::normalizar($respostas);
        $alertas = [];

        foreach (self::perguntas() as $pergunta) {
            $sinaliza = $pergunta['sinaliza'] ?? null;

            if ($sinaliza !== null && ($respostas[$pergunta['chave']] ?? null) === $sinaliza['valor']) {
                $alertas[] = $sinaliza['texto'];
            }
        }

        return $alertas;
    }

    /**
     * As respostas gravadas são de uma rubrica anterior? Acontece com a
     * avaliação enviada antes da troca para o documento de 2026: a nota dela
     * continua valendo, mas nenhuma chave casa com as perguntas de agora.
     *
     * @param  array<string, mixed>|null  $respostas
     */
    public static function deOutraRubrica(?array $respostas): bool
    {
        return ! empty($respostas) && array_intersect(array_keys($respostas), self::chaves()) === [];
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
            'blocos' => array_map(
                fn (string $chave, string $titulo) => ['chave' => $chave, 'titulo' => $titulo, 'maximo' => self::maximoDoBloco($chave)],
                array_keys(self::BLOCOS),
                array_values(self::BLOCOS),
            ),
            'secoes' => array_map(fn (array $secao) => [
                ...$secao,
                'maximo' => self::maximoDaSecao($secao['chave']),
                'perguntas' => array_map(fn (array $p) => [
                    ...$p,
                    'peso' => round($p['peso'], 3),
                    'bloco' => self::bloco($p),
                    'opcoes' => self::opcoes($p),
                ], $secao['perguntas']),
            ], self::SECOES),
        ];
    }

    /**
     * O valor que a pergunta aceita, ou null. Sim/Não chega também como
     * booleano ("1"/"0" do JSON do cliente); a escala, como número.
     *
     * @param  array<string, mixed>  $pergunta
     */
    private static function valor(array $pergunta, mixed $valor): int|string|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($pergunta['tipo'] === self::TIPO_ESCALA) {
            return is_numeric($valor) && in_array((int) $valor, array_column(self::opcoes($pergunta), 'valor'), true)
                ? (int) $valor
                : null;
        }

        if (is_bool($valor)) {
            return $valor ? self::SIM : self::NAO;
        }

        $valor = (string) $valor;

        return in_array($valor, array_column(self::opcoes($pergunta), 'valor'), true) ? $valor : null;
    }

    /**
     * Pontos de uma pergunta nestas respostas (já normalizadas): a fração da
     * opção escolhida vezes o peso — ou, quando a condição foi respondida e
     * não se cumpriu, a fração fixa que o documento manda dar.
     *
     * @param  array<string, mixed>  $pergunta
     * @param  array<string, int|string>  $respostas
     */
    private static function pontos(array $pergunta, array $respostas): float
    {
        $condicao = $pergunta['condicao'] ?? null;

        if ($condicao !== null && ! self::aplicavel($pergunta, $respostas)) {
            return array_key_exists($condicao['pergunta'], $respostas)
                ? $condicao['fracao_senao'] * $pergunta['peso']
                : 0.0;
        }

        $resposta = $respostas[$pergunta['chave']] ?? null;

        if ($resposta === null) {
            return 0.0;
        }

        foreach (self::opcoes($pergunta) as $opcao) {
            if ($opcao['valor'] === $resposta) {
                return $opcao['fracao'] * $pergunta['peso'];
            }
        }

        return 0.0;
    }
}
