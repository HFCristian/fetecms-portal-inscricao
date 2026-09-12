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
use App\Support\Rubrica;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monta o **exemplo da aba Ajustes**: um projeto de mentira avaliado por **três
 * avaliadores**, cada um deixando uma sugestão de reclassificação e as duas
 * recomendações escritas.
 *
 * Três, e não uma: é assim que a aba fica na feira de verdade, quando o projeto
 * recebe o mínimo de avaliações. O orientador precisa ver de antemão o caso
 * difícil — **duas sugestões concorrentes de área** ("Avaliador 1" quer uma,
 * "Avaliador 3" quer outra, e aceitar uma desliga a outra) ao lado de uma
 * sugestão de subárea isolada. Com um avaliador só, a tela parecia sempre
 * consensual.
 *
 * Por que um comando e não SQL solto: aqui as senhas passam pelo hash do
 * framework, os enums e casts (`respostas` em JSON, datas) são gravados no
 * formato que o portal lê, e rodar duas vezes não duplica nada. O SQL
 * equivalente está documentado em `docs/DEMO_AJUSTES.md` para quem precisar
 * fazer isso direto no banco.
 *
 * Todas as contas nascem com `is_demo = true`, então o projeto fica fora do
 * painel, do ranking, da lista final, da distribuição automática e das telas de
 * avaliação — ver `Projeto::semDemo()`.
 */
class SemearAjustesDemo extends Command
{
    protected $signature = 'demo:ajustes
        {--orientador=orientador@fetecms.test : e-mail do orientador que verá as sugestões}
        {--avaliador=avaliador.demo@fetecms.test : e-mail do primeiro avaliador demo}
        {--senha=fetecms-demo : senha das contas, quando criadas}';

    protected $description = 'Cria (ou atualiza) o projeto-exemplo com três sugestões para a aba Ajustes do orientador';

    public function handle(): int
    {
        $areas = Area::orderBy('id')->take(3)->get();

        if ($areas->count() < 2) {
            $this->error('É preciso ter ao menos duas áreas cadastradas. Rode o CatalogoSeeder antes.');

            return self::FAILURE;
        }

        $areaAtual = $areas[0];
        $areaSugerida = $areas[1];
        // Com só duas áreas no catálogo, o terceiro avaliador repete a segunda —
        // a tela continua mostrando as sugestões concorrentes.
        $areaAlternativa = $areas[2] ?? $areas[1];

        $edicao = Edicao::padrao() ?? Edicao::first();

        if ($edicao === null) {
            $this->error('Nenhuma edição cadastrada. Crie a edição da feira antes.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($areaAtual, $areaSugerida, $areaAlternativa, $edicao) {
            $orientador = $this->orientadorDemo();
            $projeto = $this->projetoDemo($orientador, $edicao, $areaAtual);

            // A subárea que o segundo avaliador sugere é **outra da mesma área**:
            // é o caso de "a área está certa, a subárea não".
            $outraSubarea = Subarea::where('area_id', $areaAtual->id)
                ->where('id', '!=', $projeto->subarea_id)
                ->value('id') ?? $projeto->subarea_id;

            $sugestoes = [
                [
                    'email' => $this->option('avaliador'),
                    'nome' => 'Avaliador Demonstração 1',
                    'cpf' => '00000000272',
                    'area' => $areaSugerida,
                    // Só a área: a subárea fica marcada como correta.
                    'area_id' => $areaSugerida->id,
                    'subarea_id' => null,
                    'video' => 'O vídeo está bom, mas o áudio da narração satura em alguns trechos.',
                    'projeto' => 'O trabalho se encaixa melhor em outra área do conhecimento — veja a sugestão.',
                ],
                [
                    'email' => $this->emailDerivado(2),
                    'nome' => 'Avaliador Demonstração 2',
                    'cpf' => '00000000353',
                    'area' => $areaAtual,
                    // Só a subárea: a área está certa.
                    'area_id' => null,
                    'subarea_id' => $outraSubarea,
                    'video' => 'Mostre o experimento em funcionamento já nos primeiros 30 segundos.',
                    'projeto' => 'A área está correta; a subárea é que não descreve bem o objeto do estudo.',
                ],
                [
                    'email' => $this->emailDerivado(3),
                    'nome' => 'Avaliador Demonstração 3',
                    'cpf' => '00000000434',
                    'area' => $areaAlternativa,
                    // Outra área: entra em conflito com a do Avaliador 1, que é
                    // justamente o caso que o orientador precisa ensaiar.
                    'area_id' => $areaAlternativa->id,
                    'subarea_id' => null,
                    'video' => 'Faltou creditar a escola e a equipe no encerramento.',
                    'projeto' => 'Detalhe a metodologia e inclua as referências consultadas.',
                ],
            ];

            foreach ($sugestoes as $sugestao) {
                $avaliador = $this->avaliadorDemo($sugestao);
                $this->avaliacaoDemo($projeto, $avaliador, $sugestao);
            }

            $this->components->info("Orientador: {$orientador->email}");
            $this->components->info("Projeto: #{$projeto->id} — {$projeto->titulo}");
            $this->components->info(sprintf(
                'Três sugestões: área "%s" (Avaliador 1), subárea (Avaliador 2) e área "%s" (Avaliador 3).',
                $areaSugerida->nome,
                $areaAlternativa->nome,
            ));
        });

        $this->newLine();
        $this->line('Entre como o orientador, abra <options=bold>Ajustes</> e ligue o');
        $this->line('<options=bold>Modo de teste</> — ele ignora o período definido pela organização.');

        return self::SUCCESS;
    }

    /** avaliador.demo@x → avaliador.demo2@x, para as contas 2 e 3. */
    private function emailDerivado(int $n): string
    {
        $base = (string) $this->option('avaliador');
        [$usuario, $dominio] = array_pad(explode('@', $base, 2), 2, 'fetecms.test');

        return $usuario.$n.'@'.$dominio;
    }

    /** O orientador dono do projeto-exemplo. `is_demo` é o que o isola dos números. */
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
     * Quem "avaliou" o projeto-exemplo — também demo, para não entrar em
     * ranking. Cada um com o seu CPF: a coluna é única, e três perfis com o
     * mesmo número não entram no banco.
     *
     * @param  array<string, mixed>  $sugestao
     */
    private function avaliadorDemo(array $sugestao): User
    {
        $user = User::firstOrNew(['email' => $sugestao['email']]);
        $user->fill([
            'name' => $user->name ?? $sugestao['nome'],
            'role' => Role::Avaliador,
            'is_active' => true,
            'is_demo' => true,
        ]);
        $user->password = $user->exists ? $user->password : $this->option('senha');
        $user->save();

        $user->avaliadorProfile()->firstOrCreate([], [
            'cpf' => $sugestao['cpf'],
            'titulacao' => 'Mestrado (concluído)',
            'area_id' => $sugestao['area']->id,
        ]);

        return $user;
    }

    private function projetoDemo(User $orientador, Edicao $edicao, Area $area): Projeto
    {
        $projeto = Projeto::withoutGlobalScopes()->firstOrNew([
            'user_id' => $orientador->id,
            'titulo' => 'Projeto de demonstração — ajustes de classificação',
        ]);

        $projeto->fill([
            'edicao_id' => $edicao->id,
            'categoria' => Categoria::Fetecms,
            'area_id' => $area->id,
            'subarea_id' => $projeto->subarea_id ?? Subarea::where('area_id', $area->id)->value('id'),
            'resumo' => 'Projeto fictício, criado para demonstrar a aba Ajustes do orientador.',
            'status' => ProjetoStatus::Submetido,
            'submitted_at' => $projeto->submitted_at ?? now(),
        ]);
        $projeto->save();

        return $projeto;
    }

    /**
     * Uma avaliação concluída com a sugestão pedida.
     *
     * O que a aba Ajustes lê é `area_correta = false` + `area_sugerida_id`
     * (idem para a subárea): é essa combinação que vira uma linha decidível na
     * tela. Marcar o campo como **correto** é o que faz a sugestão daquele tipo
     * **não** aparecer — e é assim que cada avaliador deixa uma sugestão só.
     *
     * @param  array<string, mixed>  $sugestao
     */
    private function avaliacaoDemo(Projeto $projeto, User $avaliador, array $sugestao): Avaliacao
    {
        $avaliacao = Avaliacao::comDevolvidas()->firstOrNew([
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
        ]);

        // Respostas cheias, para a nota sair coerente com a rubrica oficial.
        // `normalizar()` converte cada valor para o formato que a rubrica grava
        // (booleano no sim/não, inteiro na escala).
        $respostas = Rubrica::normalizar(
            collect(Rubrica::perguntas())
                ->mapWithKeys(fn (array $p) => [
                    $p['chave'] => $p['tipo'] === Rubrica::TIPO_SIM_NAO ? true : 8,
                ])
                ->all()
        );

        $avaliacao->fill([
            'status' => StatusAvaliacao::Concluida,
            'respostas' => $respostas,
            'nota' => Rubrica::nota($respostas),
            'area_correta' => $sugestao['area_id'] === null,
            'area_sugerida_id' => $sugestao['area_id'],
            'subarea_correta' => $sugestao['subarea_id'] === null,
            'subarea_sugerida_id' => $sugestao['subarea_id'],
            'comentario_video' => $sugestao['video'],
            'comentario_projeto' => $sugestao['projeto'],
            'designacao_manual' => true,
            'devolvida_em' => null,
            'concluida_em' => $avaliacao->concluida_em ?? now(),
        ]);
        $avaliacao->save();

        return $avaliacao;
    }
}
