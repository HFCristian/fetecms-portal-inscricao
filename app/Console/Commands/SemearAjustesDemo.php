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
 * Monta o **exemplo da aba Ajustes**: um projeto de mentira, avaliado por um
 * avaliador demo, com sugestões de troca de área e de subárea e as duas
 * recomendações escritas — exatamente o que o orientador precisa ter na tela
 * para ver a aba funcionando.
 *
 * Por que um comando e não SQL solto: aqui as senhas passam pelo hash do
 * framework, os enums e casts (`respostas` em JSON, datas) são gravados no
 * formato que o portal lê, e rodar duas vezes não duplica nada. O SQL
 * equivalente está documentado em `docs/DEMO_AJUSTES.md` para quem precisar
 * fazer isso direto no banco.
 *
 * Os dois usuários nascem com `is_demo = true`, então o projeto fica fora do
 * painel, do ranking, da lista final e da distribuição automática — ver
 * `Projeto::semDemo()`.
 */
class SemearAjustesDemo extends Command
{
    protected $signature = 'demo:ajustes
        {--orientador=orientador.demo@fetecms.test : e-mail do orientador demo}
        {--avaliador=avaliador.demo@fetecms.test : e-mail do avaliador demo}
        {--senha=fetecms-demo : senha das duas contas, quando criadas}';

    protected $description = 'Cria (ou atualiza) o projeto-exemplo com sugestões para a aba Ajustes do orientador demo';

    public function handle(): int
    {
        $areas = Area::orderBy('id')->take(2)->get();

        if ($areas->count() < 2) {
            $this->error('É preciso ter ao menos duas áreas cadastradas. Rode o CatalogoSeeder antes.');

            return self::FAILURE;
        }

        [$areaAtual, $areaSugerida] = [$areas[0], $areas[1]];
        $edicao = Edicao::padrao() ?? Edicao::first();

        if ($edicao === null) {
            $this->error('Nenhuma edição cadastrada. Crie a edição da feira antes.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($areaAtual, $areaSugerida, $edicao) {
            $orientador = $this->orientadorDemo();
            $avaliador = $this->avaliadorDemo($areaSugerida);
            $projeto = $this->projetoDemo($orientador, $edicao, $areaAtual);
            $this->avaliacaoDemo($projeto, $avaliador, $areaSugerida);

            $this->components->info("Orientador demo: {$orientador->email}");
            $this->components->info("Avaliador demo:  {$avaliador->email}");
            $this->components->info("Projeto: #{$projeto->id} — {$projeto->titulo}");
            $this->components->info(
                "Sugestão: trocar a área de \"{$areaAtual->nome}\" para \"{$areaSugerida->nome}\"."
            );
        });

        $this->newLine();
        $this->line('Entre como o orientador demo, abra <options=bold>Ajustes</> e ligue o');
        $this->line('<options=bold>Modo de teste</> — ele ignora o período definido pela organização.');

        return self::SUCCESS;
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

    /** Quem "avaliou" o projeto-exemplo — também demo, para não entrar em ranking. */
    private function avaliadorDemo(Area $area): User
    {
        $user = User::firstOrNew(['email' => $this->option('avaliador')]);
        $user->fill([
            'name' => $user->name ?? 'Avaliador Demonstração',
            'role' => Role::Avaliador,
            'is_active' => true,
            'is_demo' => true,
        ]);
        $user->password = $user->exists ? $user->password : $this->option('senha');
        $user->save();

        $user->avaliadorProfile()->firstOrCreate([], [
            'cpf' => '00000000272',
            'titulacao' => 'Mestrado (concluído)',
            'area_id' => $area->id,
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
            'subarea_id' => Subarea::where('area_id', $area->id)->value('id'),
            'resumo' => 'Projeto fictício, criado para demonstrar a aba Ajustes do orientador.',
            'status' => ProjetoStatus::Submetido,
            'submitted_at' => $projeto->submitted_at ?? now(),
        ]);
        $projeto->save();

        return $projeto;
    }

    /**
     * A avaliação concluída que gera as sugestões. O que a aba Ajustes lê é
     * `area_correta = false` + `area_sugerida_id` (idem para a subárea): é essa
     * combinação que vira uma linha decidível na tela.
     */
    private function avaliacaoDemo(Projeto $projeto, User $avaliador, Area $areaSugerida): Avaliacao
    {
        $avaliacao = Avaliacao::firstOrNew([
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
            'area_correta' => false,
            'area_sugerida_id' => $areaSugerida->id,
            'subarea_correta' => false,
            'subarea_sugerida_id' => Subarea::where('area_id', $areaSugerida->id)->value('id'),
            'comentario_video' => 'Exemplo de recomendação sobre o vídeo: melhorar o áudio da narração '
                .'e mostrar o experimento em funcionamento.',
            'comentario_projeto' => 'Exemplo de recomendação sobre o projeto: detalhar a metodologia e '
                .'incluir as referências consultadas.',
            'designacao_manual' => true,
            'concluida_em' => $avaliacao->concluida_em ?? now(),
        ]);
        $avaliacao->save();

        return $avaliacao;
    }
}
