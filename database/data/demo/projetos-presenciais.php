<?php

use App\Enums\Categoria;

/*
 * Os três projetos do ensaio da avaliação presencial (`demo:avaliacao-presencial`)
 * e o conteúdo dos documentos que a equipe levaria impressos para o estande.
 *
 * Tudo aqui é **fictício** — autores, revistas e dados —, e cada PDF diz isso
 * no cabeçalho. Cada projeto exercita um caminho diferente da rubrica de 2026,
 * para o ensaio passar pelas perguntas condicionais e pelas sem valor:
 *
 * 1. completo, sem ética e sem IA, demais referências por QR Code (a 4.3 não se aplica);
 * 2. pesquisa com estudantes (TCLE/TALE anexado) e uso de IA declarado;
 * 3. sem diário de bordo, banner sem a discussão e IA usada sem declarar.
 */

return [
    [
        'titulo' => 'Horta vertical irrigada por gotejamento solar',
        'categoria' => Categoria::Fetecms,
        'aluno' => 'Elisa Moraes',
        'cenario' => 'completo · sem ética · sem IA · demais referências por QR Code (4.3 não se aplica)',
        'resumo' => 'A escola não tem área livre para horta e a rega manual falha nos fins de semana. A equipe montou uma horta vertical com garrafas PET e um sistema de gotejamento movido por uma bomba de 12 V ligada a um painel solar. Em oito semanas, os canteiros irrigados pelo sistema tiveram perda de mudas 40% menor que os regados à mão e consumiram 35% menos água.',
        'palavras_chave' => ['horta vertical', 'irrigação por gotejamento', 'energia solar'],
        'secoes' => [
            'Introdução' => 'Hortas escolares melhoram a alimentação e aproximam os estudantes da ciência, mas dependem de espaço e de rega regular (SILVA; SOUZA, 2023). Na nossa escola, a horta antiga foi desativada por falta de área e por perdas nos fins de semana.',
            'Pergunta de pesquisa' => 'Um sistema de gotejamento alimentado por energia solar mantém uma horta vertical irrigada sem intervenção diária e com menos água que a rega manual?',
            'Objetivos' => "Geral: construir e testar uma horta vertical com irrigação solar automática.\nEspecíficos: (1) dimensionar painel, bateria e bomba; (2) comparar perda de mudas e consumo de água com a rega manual; (3) estimar o custo para outras escolas.",
            'Metodologia' => 'Foram montados dois módulos de 24 garrafas com alface e cebolinha: um com gotejamento solar (timer de 3 regas por dia) e outro regado à mão pelos estudantes. Durante oito semanas registramos, no diário de bordo, o volume de água, a umidade do solo (sensor capacitivo) e as mudas perdidas.',
            'Resultados' => 'O módulo com gotejamento perdeu 3 das 24 mudas; o manual perdeu 5. O consumo foi de 41 L contra 63 L. A umidade do solo ficou entre 35% e 48% no módulo automático e caiu abaixo de 20% em dois fins de semana no manual.',
            'Discussão' => 'A diferença no consumo concorda com o que OLIVEIRA (2022) descreve para gotejamento em pequena escala. A perda de mudas no módulo automático aconteceu numa semana nublada, quando a bateria não carregou: o sistema depende de uma reserva maior para dias sem sol.',
            'Conclusão' => 'A horta vertical solar reduziu a perda de mudas e o consumo de água sem rega diária. Para outras escolas, recomendamos uma bateria com o dobro da capacidade usada e um aviso de nível baixo.',
        ],
        'referencias' => [
            'principais' => [
                'SILVA, A. B.; SOUZA, C. D. Hortas escolares e alimentação saudável. Revista Fictícia de Educação Ambiental, v. 4, n. 1, p. 12-25, 2023.',
                'OLIVEIRA, M. R. Irrigação por gotejamento em pequena escala. Campo Grande: Editora Exemplo, 2022.',
                'PEREIRA, J. L. Bombeamento de água com energia fotovoltaica. Revista Fictícia de Energia, v. 9, n. 3, p. 40-52, 2021.',
                'COSTA, F. A. Sensores de umidade do solo de baixo custo. Anais do Simpósio Fictício de Agrotecnologia, p. 88-95, 2024.',
                'ALMEIDA, R. T. Cultivo de hortaliças em garrafas PET. Dourados: Editora Exemplo, 2020.',
            ],
            'demais' => [
                'MARTINS, L. C. Consumo de água em hortas urbanas. Revista Fictícia de Hidrologia, v. 2, n. 2, p. 5-14, 2019.',
                'RIBEIRO, P. S. Microcontroladores na escola. São Paulo: Editora Exemplo, 2021.',
            ],
            'demais_qr' => true,
        ],
        'diario' => [
            ['2026-03-09', 'Reunião com a orientadora: problema da horta antiga e ideia da horta vertical. Listamos o material.'],
            ['2026-03-16', 'Coleta de 60 garrafas PET. Testamos dois tipos de corte; o corte lateral segurou melhor a terra.'],
            ['2026-03-30', 'Montagem do painel, da bateria e da bomba. A bomba não ligava: polaridade invertida no relé.'],
            ['2026-04-06', 'Primeira rega automática. Ajustamos o timer para 3 regas de 2 minutos.'],
            ['2026-04-20', 'Semana nublada: bateria descarregou na quinta. Duas mudas murcharam no módulo automático.'],
            ['2026-06-01', 'Fim das oito semanas. Tabulamos água, umidade e mudas perdidas. Começamos o banner.'],
        ],
        'etica' => null,
        'ia' => null,
        'banner_sem' => [],
    ],
    [
        'titulo' => 'Jogo de tabuleiro para ensinar frações',
        'categoria' => Categoria::FetecJr,
        'aluno' => 'Felipe Antunes',
        'cenario' => 'pesquisa com estudantes (TCLE/TALE anexado) · IA usada e declarada · todas as referências impressas',
        'resumo' => 'Muitos colegas do 6º ano erram a comparação de frações. A equipe criou o jogo de tabuleiro "Trilha das Frações" e aplicou um teste antes e depois de quatro aulas com o jogo em duas turmas. A turma que jogou passou de 48% para 71% de acertos; a turma que teve aula expositiva foi de 50% para 58%.',
        'palavras_chave' => ['frações', 'jogos educativos', 'ensino de matemática'],
        'secoes' => [
            'Introdução' => 'Comparar e somar frações é uma das dificuldades mais citadas no início do ensino fundamental II (LIMA, 2021). Jogos de tabuleiro podem tornar o conteúdo concreto e aumentar a participação (GOMES; ROCHA, 2020).',
            'Pergunta de pesquisa' => 'Um jogo de tabuleiro sobre frações melhora o desempenho de estudantes do 6º ano em comparação com a aula expositiva?',
            'Objetivos' => "Geral: criar e avaliar um jogo de tabuleiro para o ensino de frações.\nEspecíficos: (1) criar as regras e as cartas do jogo; (2) aplicar teste antes e depois em duas turmas; (3) comparar os resultados.",
            'Metodologia' => 'Duas turmas de 6º ano (52 estudantes) fizeram um teste de 10 questões. A turma A jogou a "Trilha das Frações" em quatro aulas; a turma B teve aulas expositivas. Depois, o mesmo teste foi repetido. Os responsáveis assinaram o TCLE e os estudantes o TALE, e nenhum nome foi registrado nas folhas de resposta.',
            'Resultados' => 'Turma A: média de 48% de acertos antes e 71% depois. Turma B: 50% antes e 58% depois. As questões de comparação de frações com denominadores diferentes tiveram o maior ganho na turma A.',
            'Discussão' => 'O ganho maior na turma A concorda com GOMES e ROCHA (2020), mas a amostra é pequena e as turmas tiveram professoras diferentes, o que pode ter influenciado o resultado.',
            'Conclusão' => 'O jogo ajudou a turma A a melhorar mais que a turma B, principalmente na comparação de frações. Pretendemos repetir o teste com mais turmas e com a mesma professora.',
            'Declaração de uso de IA' => 'Usamos o ChatGPT (OpenAI) apenas para revisar a ortografia do resumo e para sugerir nomes para as cartas do jogo. As regras, o teste e a análise foram feitos pela equipe. (Item 8.5, V, do edital.)',
        ],
        'referencias' => [
            'principais' => [
                'LIMA, S. P. Dificuldades no ensino de frações. Revista Fictícia de Educação Matemática, v. 7, n. 2, p. 30-44, 2021.',
                'GOMES, T. A.; ROCHA, V. M. Jogos de tabuleiro na sala de aula. Campo Grande: Editora Exemplo, 2020.',
                'FERREIRA, D. C. Avaliação diagnóstica em matemática. Revista Fictícia de Avaliação Escolar, v. 3, n. 1, p. 9-20, 2022.',
                'NUNES, H. R. Materiais concretos e números racionais. Anais do Encontro Fictício de Matemática, p. 101-110, 2019.',
                'BARBOSA, K. L. Ética na pesquisa com estudantes. Brasília: Editora Exemplo, 2023.',
            ],
            'demais' => [
                'CARDOSO, E. F. Gamificação no ensino fundamental. Revista Fictícia de Tecnologia Educacional, v. 5, n. 4, p. 60-71, 2024.',
                'MENDES, A. J. Estatística para a sala de aula. São Paulo: Editora Exemplo, 2018.',
            ],
            'demais_qr' => false,
        ],
        'diario' => [
            ['2026-02-23', 'Conversamos com a professora de matemática sobre os erros mais comuns nas provas de frações.'],
            ['2026-03-02', 'Primeiro rascunho do tabuleiro em papel pardo. Regra das cartas de "troca" ficou confusa.'],
            ['2026-03-12', 'Entregamos os termos (TCLE para os pais e TALE para os colegas). 49 de 52 devolvidos assinados.'],
            ['2026-03-19', 'Aplicamos o teste diagnóstico nas duas turmas.'],
            ['2026-04-09', 'Quarta aula com o jogo na turma A. Os alunos pediram para levar o jogo para casa.'],
            ['2026-04-16', 'Teste final nas duas turmas e tabulação. Usamos IA só para revisar a ortografia do resumo.'],
        ],
        'etica' => [
            'nome' => 'TCLE e TALE.pdf',
            'texto' => 'Termo de Consentimento Livre e Esclarecido (TCLE), assinado pelos responsáveis, e Termo de Assentimento Livre e Esclarecido (TALE), assinado pelos estudantes participantes do teste do jogo. Participação voluntária, sem identificação nas respostas e com a possibilidade de desistir a qualquer momento.',
        ],
        'ia' => 'Declaramos o uso do ChatGPT (OpenAI) para revisar a ortografia do resumo e sugerir nomes de cartas. Nenhum dado ou resultado foi gerado por IA.',
        'banner_sem' => [],
    ],
    [
        'titulo' => 'Filtro de água com carvão de casca de coco',
        'categoria' => Categoria::FetecmsFundect,
        'aluno' => 'Giovana Prado',
        'cenario' => 'sem diário de bordo · banner sem a discussão · IA usada sem declarar (a equipe conta na conversa)',
        'resumo' => 'Na comunidade da escola, a água de poço tem cor e cheiro. A equipe produziu carvão ativado a partir de casca de coco e montou um filtro de três camadas. Depois da filtração, a turbidez caiu de 38 NTU para 4 NTU e o odor deixou de ser percebido por 9 de 10 voluntários adultos.',
        'palavras_chave' => ['carvão ativado', 'casca de coco', 'tratamento de água'],
        'secoes' => [
            'Introdução' => 'A casca de coco é descartada em grande quantidade e pode virar carvão ativado de baixo custo (TEIXEIRA, 2021). Filtros caseiros com carvão melhoram a cor e o odor da água, mas não a desinfetam (ARAÚJO; LOPES, 2022).',
            'Pergunta de pesquisa' => 'Um filtro com carvão de casca de coco reduz a turbidez e o odor da água de poço da comunidade?',
            'Objetivos' => "Geral: produzir carvão de casca de coco e avaliar um filtro caseiro.\nEspecíficos: (1) produzir e ativar o carvão; (2) montar o filtro de três camadas; (3) medir a turbidez antes e depois.",
            'Metodologia' => 'As cascas foram secas, carbonizadas em lata fechada e ativadas com solução de cloreto de cálcio. O filtro tem camadas de areia, carvão e cascalho. A turbidez foi medida com turbidímetro emprestado pela universidade, em cinco amostras.',
            'Resultados' => 'A turbidez média caiu de 38 NTU para 4 NTU. Em teste cego, 9 de 10 voluntários adultos não perceberam odor na água filtrada.',
            'Discussão' => 'A redução da turbidez ficou próxima da relatada por TEIXEIRA (2021). O filtro não elimina microrganismos: a água ainda precisa ser fervida ou clorada antes de beber.',
            'Conclusão' => 'O carvão de casca de coco funcionou para cor, turbidez e odor. O próximo passo é medir quanto tempo o carvão dura antes de saturar.',
        ],
        'referencias' => [
            'principais' => [
                'TEIXEIRA, B. N. Carvão ativado de resíduos agrícolas. Revista Fictícia de Química Ambiental, v. 6, n. 1, p. 15-27, 2021.',
                'ARAÚJO, G. F.; LOPES, I. M. Filtros caseiros e qualidade da água. Campo Grande: Editora Exemplo, 2022.',
                'MOREIRA, C. P. Turbidez e potabilidade. Revista Fictícia de Saneamento, v. 11, n. 2, p. 33-41, 2020.',
                'SANTOS, R. V. Aproveitamento da casca de coco. Anais do Congresso Fictício de Resíduos, p. 210-218, 2023.',
                'VIEIRA, L. O. Adsorção para o ensino médio. São Paulo: Editora Exemplo, 2019.',
            ],
            'demais' => [
                'CAMPOS, M. A. Água de poço em comunidades rurais. Revista Fictícia de Saúde Pública, v. 8, n. 3, p. 70-79, 2024.',
            ],
            'demais_qr' => false,
        ],
        'diario' => null,
        'etica' => null,
        'ia' => null,
        // O banner foi impresso sem a seção de discussão: 5.1 = Não.
        'banner_sem' => ['Discussão'],
    ],
];
