<?php

namespace App\Console\Commands;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use App\Services\PareceresOrientadorService;
use App\Support\Rubrica;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monta o **projeto-exemplo do pós-avaliação**: um projeto submetido que já
 * passou pela avaliação online e chega ao orientador com as duas telas cheias —
 * a aba **Pareceres** (nota média, pontos fortes/médios/fracos e as
 * recomendações escritas) e a aba **Ajustes** (as sugestões de área e subárea,
 * que ele aceita ou desfaz).
 *
 * Por que não bastava o `demo:ajustes`: aquele comando responde todas as 17
 * perguntas da rubrica com o mesmo valor (8 / Sim), o que é suficiente para as
 * sugestões aparecerem, mas faz a aba Pareceres dizer **"Ponto forte" nas dez
 * seções**. Quem está ensaiando a tela precisa justamente do contrário — ver a
 * mistura, porque é ela que o orientador vai ler depois da feira.
 *
 * Daí a {@see self::PERFIL}: em vez de responder pergunta a pergunta, o comando
 * descreve **quanto cada seção rendeu na cabeça de cada um dos três
 * avaliadores** (0 a 10) e deixa a rubrica converter isso em resposta — escala
 * de dois em dois nas perguntas "de que modo…", Sim/Não nas outras. É assim que
 * as notas saem coerentes com os pesos do edital sem ninguém ter de calcular o
 * 0,5375 de Objetivos à mão, e é o que faz o nível de cada seção cair onde se
 * quer: {@see PareceresOrientadorService} normaliza a seção em 0 a 10 e corta em
 * 8 (forte) e 4 (médio).
 *
 * As contas nascem com `is_demo = true` — o que as tira do painel, do ranking,
 * da lista final e da distribuição automática ({@see Projeto::semDemo()}) e, do
 * outro lado, é o que **libera o "Modo de teste"** nas duas abas: ele ignora o
 * período definido pela organização, que é como se ensaia antes do prazo
 * oficial. As três contas de avaliador são as mesmas do `demo:ajustes`
 * (e-mails e CPFs), então rodar os dois comandos não multiplica cadastro.
 *
 * Rodar duas vezes não duplica nada.
 */
class SemearPareceresDemo extends Command
{
    protected $signature = 'demo:pareceres
        {--orientador=orientador@fetecms.test : e-mail do orientador que verá os pareceres e os ajustes}
        {--avaliador=avaliador.demo@fetecms.test : e-mail do primeiro avaliador demo}
        {--senha=fetecms-demo : senha das contas, quando criadas}';

    protected $description = 'Cria (ou atualiza) um projeto submetido já avaliado, com pareceres e ajustes para o orientador demo';

    /**
     * Quanto cada seção pontuada da rubrica rendeu, de 0 a 10, na avaliação de
     * cada um dos três avaliadores — na ordem em que eles aparecem na tela
     * ("Avaliador 1", "Avaliador 2", "Avaliador 3").
     *
     * A média das três é o que vira o **nível** na aba Pareceres, então o
     * desenho aqui é proposital: quatro seções fortes, quatro médias e duas
     * fracas, com os avaliadores discordando um pouco entre si — um projeto em
     * que tudo é forte não ensina nada a quem está conferindo a tela.
     *
     * As duas seções sem pergunta pontuada (`geral_inicio` e `final`) não
     * entram: a primeira é a conferência de classificação, a segunda são os
     * campos de recomendação.
     *
     * @var array<string, array{int, int, int}>
     */
    private const PERFIL = [
        'titulo' => [10, 10, 10],       // forte  — Sim dos três
        'resumo' => [8, 10, 8],         // forte
        'introducao' => [6, 6, 4],      // médio
        'objetivos' => [8, 10, 8],      // forte
        'metodologia' => [6, 4, 6],     // médio
        'resultados' => [4, 2, 2],      // fraco  — o que as recomendações cobram
        'conclusao' => [6, 6, 6],       // médio
        'referencias' => [2, 0, 4],     // fraco  — idem
        'geral_projeto' => [4, 6, 6],   // médio
        'video' => [10, 8, 10],         // forte
    ];

    public function handle(PareceresOrientadorService $pareceres): int
    {
        $areas = Area::orderBy('id')->take(3)->get();

        if ($areas->count() < 2) {
            $this->error('É preciso ter ao menos duas áreas cadastradas. Rode o CatalogoSeeder antes.');

            return self::FAILURE;
        }

        $edicao = Edicao::padrao() ?? Edicao::first();

        if ($edicao === null) {
            $this->error('Nenhuma edição cadastrada. Crie a edição da feira antes.');

            return self::FAILURE;
        }

        $areaAtual = $areas[0];
        $areaSugerida = $areas[1];
        // Com só duas áreas no catálogo, o terceiro avaliador repete a segunda —
        // a tela continua mostrando as duas sugestões concorrentes.
        $areaAlternativa = $areas[2] ?? $areas[1];

        $projeto = DB::transaction(function () use ($areaAtual, $areaSugerida, $areaAlternativa, $edicao) {
            $orientador = $this->orientadorDemo();
            $projeto = $this->projetoDemo($orientador, $edicao, $areaAtual);

            // A subárea que o segundo avaliador sugere é **outra da mesma
            // área**: é o caso de "a área está certa, a subárea não".
            $outraSubarea = Subarea::where('area_id', $areaAtual->id)
                ->where('id', '!=', $projeto->subarea_id)
                ->value('id') ?? $projeto->subarea_id;

            foreach ($this->avaliadores($areaAtual, $areaSugerida, $areaAlternativa, $outraSubarea) as $i => $dados) {
                $this->avaliacaoDemo($projeto, $this->avaliadorDemo($dados), $dados, $i);
            }

            $this->components->info("Orientador: {$orientador->email}");
            $this->components->info("Projeto: #{$projeto->id} — {$projeto->titulo}");

            return $projeto;
        });

        $this->resumo($pareceres->detalhe($projeto->refresh()));

        $this->newLine();
        $this->line('Entre como o orientador e abra <options=bold>Pareceres</> e <options=bold>Ajustes</>.');
        $this->line('Em cada uma, ligue o <options=bold>Modo de teste</>: ele ignora o período');
        $this->line('de ajustes definido pela organização, que é o que permite conferir as');
        $this->line('duas telas antes do prazo oficial.');

        return self::SUCCESS;
    }

    /**
     * Os três avaliadores do exemplo: um sugere a área, outro a subárea e o
     * terceiro **outra área**, disputando com o primeiro — aceitar uma desliga
     * a outra, e é o caso difícil que o orientador precisa ensaiar.
     *
     * As recomendações escritas conversam com a {@see self::PERFIL}: elas
     * cobram justamente Resultados e Referências, as duas seções que a aba
     * Pareceres vai marcar como ponto fraco. Parecer que elogia o que a nota
     * reprova é o tipo de incoerência que atrapalha quem está conferindo a tela.
     *
     * @return list<array<string, mixed>>
     */
    private function avaliadores(Area $atual, Area $sugerida, Area $alternativa, ?int $outraSubarea): array
    {
        return [
            [
                'email' => (string) $this->option('avaliador'),
                'nome' => 'Avaliador Demonstração 1',
                'cpf' => '00000000272',
                'area' => $sugerida,
                // Só a área: a subárea fica marcada como correta.
                'area_id' => $sugerida->id,
                'subarea_id' => null,
                'video' => 'A edição está boa e a equipe aparece à vontade, mas o áudio satura quando falam juntos. Grave a narração à parte e legende os trechos gravados em campo.',
                'projeto' => 'Título e objetivos muito claros, e o vídeo mostra domínio do tema. Os resultados, porém, aparecem sem discussão: relacione cada gráfico com o que as referências já apontavam. Pela abordagem, o trabalho se encaixa melhor em outra área do conhecimento — veja a sugestão.',
            ],
            [
                'email' => $this->emailDerivado(2),
                'nome' => 'Avaliador Demonstração 2',
                'cpf' => '00000000353',
                'area' => $atual,
                // Só a subárea: a área está certa.
                'area_id' => null,
                'subarea_id' => $outraSubarea,
                'video' => 'Mostre o experimento funcionando nos primeiros 30 segundos — hoje ele só aparece depois da metade do vídeo.',
                'projeto' => 'As referências são poucas e, em sua maioria, de páginas sem revisão; procure artigos dos últimos cinco anos. A área está correta; a subárea é que não descreve bem o objeto do estudo.',
            ],
            [
                'email' => $this->emailDerivado(3),
                'nome' => 'Avaliador Demonstração 3',
                'cpf' => '00000000434',
                'area' => $alternativa,
                // Outra área, em conflito com a do Avaliador 1.
                'area_id' => $alternativa->id,
                'subarea_id' => null,
                'video' => 'Faltou creditar a escola e a equipe no encerramento, e a apresentação passa do tempo previsto no edital.',
                'projeto' => 'A conclusão repete os resultados em vez de respondê-los, e a formatação foge do modelo da FETECMS nas citações e nas margens. Vale uma revisão da ABNT antes da próxima edição.',
            ],
        ];
    }

    /** avaliador.demo@x → avaliador.demo2@x, para as contas 2 e 3. */
    private function emailDerivado(int $n): string
    {
        $base = (string) $this->option('avaliador');
        [$usuario, $dominio] = array_pad(explode('@', $base, 2), 2, 'fetecms.test');

        return $usuario.$n.'@'.$dominio;
    }

    /** O orientador dono do projeto-exemplo. `is_demo` é o que libera o modo de teste. */
    private function orientadorDemo(): User
    {
        $user = User::firstOrNew(['email' => $this->option('orientador')]);
        $user->fill([
            'name' => $user->name ?? 'Orientador Demonstração',
            'role' => Role::Orientador,
            'is_active' => true,
            'is_demo' => true,
        ]);
        $user->password = $user->exists ? $user->password : $this->option('senha');
        $user->save();

        $user->orientadorProfile()->firstOrCreate([], [
            'cpf' => '00000000191',
            'telefone' => '67900000000',
            'data_nascimento' => '1990-01-01',
        ]);

        return $user;
    }

    /**
     * Quem "avaliou" o projeto — também demo, para não entrar no ranking de
     * avaliadores nem gerar certificado. São as mesmas contas do `demo:ajustes`,
     * com os mesmos CPFs (a coluna é única).
     *
     * @param  array<string, mixed>  $dados
     */
    private function avaliadorDemo(array $dados): User
    {
        $user = User::firstOrNew(['email' => $dados['email']]);
        $user->fill([
            'name' => $user->name ?? $dados['nome'],
            'role' => Role::Avaliador,
            'is_active' => true,
            'is_demo' => true,
        ]);
        $user->password = $user->exists ? $user->password : $this->option('senha');
        $user->save();

        $user->avaliadorProfile()->firstOrCreate([], [
            'cpf' => $dados['cpf'],
            'titulacao' => 'Mestrado (concluído)',
            'area_id' => $dados['area']->id,
        ]);

        return $user;
    }

    private function projetoDemo(User $orientador, Edicao $edicao, Area $area): Projeto
    {
        $projeto = Projeto::withoutGlobalScopes()->firstOrNew([
            'user_id' => $orientador->id,
            'titulo' => 'Projeto de demonstração — pareceres e ajustes',
        ]);

        $projeto->fill([
            'edicao_id' => $edicao->id,
            'categoria' => Categoria::Fetecms,
            'area_id' => $area->id,
            'subarea_id' => $projeto->subarea_id ?? Subarea::where('area_id', $area->id)->value('id'),
            'resumo' => 'Projeto fictício, criado para demonstrar o que o orientador recebe depois da avaliação online: a nota média e os pontos fortes e fracos na aba Pareceres, e as sugestões de área e subárea na aba Ajustes.',
            'palavras_chave' => ['demonstração', 'avaliação online', 'pareceres'],
            'link_video' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'status' => ProjetoStatus::Submetido,
            'submitted_at' => $projeto->submitted_at ?? now()->subMonth(),
        ]);
        $projeto->save();

        return $projeto;
    }

    /**
     * Uma avaliação concluída: as respostas da rubrica (que viram a nota e os
     * níveis da aba Pareceres), as recomendações escritas e a sugestão de
     * classificação (que vira a linha decidível da aba Ajustes).
     *
     * O que a aba Ajustes lê é `area_correta = false` + `area_sugerida_id`
     * (idem para a subárea): marcar o campo como **correto** é o que faz a
     * sugestão daquele tipo **não** aparecer — e é assim que cada avaliador
     * deixa uma sugestão só.
     *
     * @param  array<string, mixed>  $dados
     * @param  int  $indice  posição do avaliador na {@see self::PERFIL}
     */
    private function avaliacaoDemo(Projeto $projeto, User $avaliador, array $dados, int $indice): Avaliacao
    {
        $avaliacao = Avaliacao::comDevolvidas()->firstOrNew([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
        ]);

        $respostas = $this->respostas($indice);

        $avaliacao->fill([
            'status' => StatusAvaliacao::Concluida,
            'respostas' => $respostas,
            'nota' => Rubrica::nota($respostas),
            'area_correta' => $dados['area_id'] === null,
            'area_sugerida_id' => $dados['area_id'],
            'subarea_correta' => $dados['subarea_id'] === null,
            'subarea_sugerida_id' => $dados['subarea_id'],
            'comentario_video' => $dados['video'],
            'comentario_projeto' => $dados['projeto'],
            'designacao_manual' => true,
            'devolvida_em' => null,
            // Uma duração plausível: o sinal de "avaliação relâmpago" da tela de
            // disparidade lê justamente este par.
            'iniciada_em' => $avaliacao->iniciada_em ?? now()->subDays(2)->subHours(2),
            'concluida_em' => $avaliacao->concluida_em ?? now()->subDays(2),
        ]);
        $avaliacao->save();

        return $avaliacao;
    }

    /**
     * As 17 respostas de um avaliador, derivadas da intensidade que a
     * {@see self::PERFIL} dá a cada seção: a pergunta de escala recebe o próprio
     * valor (a escala do edital vai de dois em dois) e a de Sim/Não vira Sim a
     * partir de 6 — abaixo disso a seção estaria sendo reprovada, e Sim/Não não
     * tem meio-termo.
     *
     * @return array<string, int|bool>
     */
    private function respostas(int $indice): array
    {
        $brutas = [];

        foreach (Rubrica::perguntas() as $pergunta) {
            $intensidade = self::PERFIL[$pergunta['secao']][$indice] ?? 6;

            $brutas[$pergunta['chave']] = $pergunta['tipo'] === Rubrica::TIPO_SIM_NAO
                ? $intensidade >= 6
                : $intensidade;
        }

        // `normalizar()` grava no formato que o portal lê: inteiro na escala,
        // booleano no Sim/Não.
        return Rubrica::normalizar($brutas);
    }

    /**
     * Imprime o que o orientador vai encontrar na tela — a conferência de que o
     * exemplo saiu com a mistura pretendida, e não dez "Ponto forte".
     *
     * @param  array<string, mixed>  $detalhe
     */
    private function resumo(array $detalhe): void
    {
        $this->components->info(sprintf(
            'Nota média: %s de %s, de %d avaliações.',
            number_format((float) $detalhe['media'], 2, ',', '.'),
            number_format((float) $detalhe['nota_maxima'], 2, ',', '.'),
            $detalhe['avaliacoes'],
        ));

        foreach ([
            PareceresOrientadorService::NIVEL_FORTE => 'Pontos fortes',
            PareceresOrientadorService::NIVEL_MEDIO => 'Pontos médios',
            PareceresOrientadorService::NIVEL_FRACO => 'Pontos fracos',
        ] as $nivel => $rotulo) {
            $secoes = array_column(array_filter(
                $detalhe['secoes'],
                fn (array $s) => $s['nivel'] === $nivel,
            ), 'titulo');

            $this->components->twoColumnDetail($rotulo, implode(', ', $secoes) ?: '—');
        }

        $this->components->twoColumnDetail('Recomendações escritas', (string) count($detalhe['recomendacoes']));
    }
}
