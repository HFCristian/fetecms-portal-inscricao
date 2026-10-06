<?php

namespace App\Console\Commands;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\Turno;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\AvaliadorTurnoPresencial;
use App\Models\ChecagemEstande;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\ItemChecagemEstande;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\JanelaTurnos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monta o **ensaio da avaliação presencial** (Sprint 172) para a conta
 * `avaliador@fetecms.test`: tudo que a aba "Avaliação presencial" e a
 * distribuição por turno precisam para serem testadas antes do evento.
 *
 * - o avaliador vira **conta demo** e **aceita** o presencial — é a marca demo
 *   que libera o "Modo de teste", que ignora o relógio do turno e a ativação
 *   na cabine;
 * - três projetos de mentira entram na **lista final de demonstração** (a
 *   mesma do `demo:credenciamento`, que corre em paralelo à oficial), já
 *   **credenciados** e com o estande **checado** — as duas condições para um
 *   projeto ser distribuído —, no turno que a agenda tem em foco;
 * - o avaliador fica **ativado** em todos os turnos que ainda não terminaram,
 *   para a distribuição do admin (também em modo de teste) alcançá-lo;
 * - o catálogo de itens da checagem ganha três itens, **só se estiver vazio**.
 *
 * Nada aqui toca em projeto, lista ou avaliador de verdade, e rodar duas vezes
 * não duplica nada.
 */
class SemearAvaliacaoPresencialDemo extends Command
{
    protected $signature = 'demo:avaliacao-presencial
        {--avaliador=avaliador@fetecms.test : e-mail do avaliador que vai ensaiar}
        {--orientador=orientador.presencial@fetecms.test : e-mail do orientador demo dono dos projetos}
        {--senha=password : senha das contas, quando criadas}';

    protected $description = 'Prepara o avaliador demo e projetos prontos para ensaiar a avaliação presencial';

    /** Os projetos-exemplo: título e categoria. */
    private const EXEMPLOS = [
        ['Horta vertical irrigada por gotejamento solar', Categoria::Fetecms],
        ['Jogo de tabuleiro para ensinar frações', Categoria::FetecJr],
        ['Filtro de água com carvão de casca de coco', Categoria::FetecmsFundect],
    ];

    /** Itens padrão da checagem, quando o catálogo está vazio. */
    private const ITENS = [
        ['Banner montado', 'O banner do projeto está exposto e legível.'],
        ['Diário de bordo', 'O caderno de campo da pesquisa está no estande.'],
        ['Protótipo ou experimento', 'O que o projeto construiu ou testou está à vista.'],
    ];

    public function handle(): int
    {
        $edicao = Edicao::padrao() ?? Edicao::first();

        if ($edicao === null) {
            $this->error('Nenhuma edição cadastrada. Crie a edição da feira antes.');

            return self::FAILURE;
        }

        $existente = User::where('email', $this->option('avaliador'))->first();

        if ($existente !== null && $existente->role !== Role::Avaliador) {
            $this->error("{$existente->email} é uma conta de {$existente->role->label()}, não de avaliador.");

            return self::FAILURE;
        }

        $janela = JanelaTurnos::daEdicao($edicao);
        $foco = $janela->emFoco();
        $turno = $foco === null ? Turno::A : Turno::from($foco['turno']);

        [$avaliador, $lista, $projetos, $ativados, $itens] = DB::transaction(function () use ($edicao, $janela, $turno) {
            $itens = $this->semearItens();
            $avaliador = $this->avaliadorDemo();
            $orientador = $this->orientadorDemo();
            $lista = $this->listaDemo($edicao);
            $projetos = [];

            foreach (self::EXEMPLOS as $i => [$titulo, $categoria]) {
                $projeto = $this->projetoPronto($orientador, $edicao, $lista, $titulo, $categoria, $turno, $i);
                $projetos[] = $projeto;
            }

            return [$avaliador, $lista, $projetos, $this->ativar($avaliador, $edicao, $janela), $itens];
        });

        $this->components->info("Avaliador demo: {$avaliador->email} (aceitou o presencial, modo de teste liberado)");
        $this->components->info("Lista de demonstração: #{$lista->id} — {$lista->nome}");

        foreach ($projetos as $projeto) {
            $this->components->twoColumnDetail($projeto->titulo, "{$turno->label()} · credenciado e checado");
        }

        $this->components->twoColumnDetail(
            'Turnos ativados',
            $ativados > 0 ? "{$ativados} turno(s) da agenda" : 'nenhum — defina as datas do evento e o horário dos turnos',
        );

        if ($itens > 0) {
            $this->components->info("Catálogo de itens da checagem vazio: {$itens} item(ns) padrão criados.");
        }

        $this->newLine();
        $this->line("Entre como <options=bold>{$avaliador->email}</>, abra <options=bold>Avaliação presencial</> e ligue o");
        $this->line('<options=bold>Modo de teste</>: os três estandes aparecem para avaliar, sem esperar o turno.');
        $this->line('Para ensaiar a distribuição, entre com um <options=bold>admin em modo demo</> e abra');
        $this->line('Avaliação presencial → <options=bold>Distribuição presencial</> com o Modo de teste ligado.');

        return self::SUCCESS;
    }

    /**
     * O avaliador do ensaio: conta demo (é o que libera o modo de teste e o
     * tira da distribuição e dos rankings de verdade) que aceitou o presencial.
     */
    private function avaliadorDemo(): User
    {
        $user = User::firstOrNew(['email' => $this->option('avaliador')]);
        $user->fill([
            'name' => $user->name ?? 'Avaliador Demo',
            'role' => Role::Avaliador,
            'is_active' => true,
            'is_demo' => true,
        ]);
        $user->password = $user->exists ? $user->password : $this->option('senha');
        $user->save();

        $perfil = $user->avaliadorProfile()->firstOrCreate([], [
            'cpf' => '71428793860',
            'titulacao' => 'Doutorado (concluído)',
            'area_id' => Area::orderBy('id')->value('id'),
            'idiomas' => ['pt'],
        ]);
        $perfil->forceFill(['presencial' => true, 'presencial_em' => $perfil->presencial_em ?? now()])->save();

        return $user;
    }

    /** O dono dos projetos de mentira. `is_demo` os tira do painel e do ranking. */
    private function orientadorDemo(): User
    {
        $user = User::firstOrNew(['email' => $this->option('orientador')]);
        $user->fill([
            'name' => $user->name ?? 'Orientador Presencial (demo)',
            'role' => Role::Orientador,
            'is_active' => true,
            'is_demo' => true,
        ]);
        $user->password = $user->exists ? $user->password : $this->option('senha');
        $user->save();

        $user->orientadorProfile()->firstOrCreate([], [
            'cpf' => '00000000272',
            'telefone' => '67900000002',
            'data_nascimento' => '1985-07-10',
        ]);

        return $user;
    }

    /** A lista paralela do ensaio — a mesma do `demo:credenciamento`. */
    private function listaDemo(Edicao $edicao): ListaFinal
    {
        $lista = ListaFinal::firstOrNew(['edicao_id' => $edicao->id, 'demo' => true]);
        $lista->fill([
            'nome' => $lista->nome ?? 'Lista de demonstração',
            'tipo' => ListaFinal::TIPO_FINAL,
            'vigente' => true,
            'rascunho' => false,
            'versao' => $lista->versao ?? 1,
        ]);
        $lista->save();

        return $lista;
    }

    /**
     * Um projeto pronto para receber avaliador: submetido, na lista demo, com
     * turno, credenciado no balcão e com o estande checado.
     */
    private function projetoPronto(User $orientador, Edicao $edicao, ListaFinal $lista, string $titulo, Categoria $categoria, Turno $turno, int $indice): Projeto
    {
        $area = Area::orderBy('id')->skip($indice)->first() ?? Area::orderBy('id')->first();

        $projeto = Projeto::withoutGlobalScopes()->firstOrNew(['user_id' => $orientador->id, 'titulo' => $titulo]);
        $projeto->fill([
            'edicao_id' => $edicao->id,
            'categoria' => $categoria,
            'area_id' => $area?->id,
            'subarea_id' => $area === null ? null : Subarea::where('area_id', $area->id)->value('id'),
            'instituicao_id' => Instituicao::orderBy('id')->value('id'),
            'resumo' => 'Projeto fictício, criado para ensaiar a avaliação presencial no estande.',
            'palavras_chave' => ['ensaio', 'avaliação presencial'],
            'status' => ProjetoStatus::Submetido,
            'submitted_at' => $projeto->submitted_at ?? now(),
        ]);
        $projeto->save();

        Aluno::firstOrCreate(
            ['projeto_id' => $projeto->id, 'cpf' => sprintf('000000009%02d', $indice)],
            [
                'nome' => ['Elisa Moraes', 'Felipe Antunes', 'Giovana Prado'][$indice] ?? 'Estudante Demo',
                'email' => "estudante.presencial.{$projeto->id}@demo.fetecms.test",
                'data_nascimento' => now()->subYears(16)->toDateString(),
                'modalidade' => 'medio',
                'ano_escolar' => '2_em',
                'instituicao_id' => $projeto->instituicao_id,
            ],
        );

        $lista->projetos()->syncWithoutDetaching([$projeto->id => ['manual' => false]]);

        TurnoApresentacao::updateOrCreate(
            ['edicao_id' => $edicao->id, 'projeto_id' => $projeto->id],
            ['turno' => $turno, 'regra' => 'demo', 'manual' => true],
        );
        $this->estande($edicao, $projeto, $turno, 901 + $indice);

        $credenciamento = Credenciamento::firstOrNew(['projeto_id' => $projeto->id]);
        $credenciamento->fill([
            'lista_final_id' => $lista->id,
            'iniciado_em' => $credenciamento->iniciado_em ?? now(),
            'finalizado_em' => $credenciamento->finalizado_em ?? now(),
        ])->save();

        $checagem = ChecagemEstande::firstOrNew(['projeto_id' => $projeto->id, 'demo' => true]);
        $checagem->fill([
            'lista_final_id' => $lista->id,
            'verificado_em' => $checagem->verificado_em ?? now(),
        ])->save();

        return $projeto;
    }

    /**
     * Um número de estande fora da faixa da prancha (901, 902…), se estiver
     * livre — o ensaio não pode ocupar o lugar de um finalista.
     */
    private function estande(Edicao $edicao, Projeto $projeto, Turno $turno, int $numero): void
    {
        $ocupado = EstandeProjeto::where('edicao_id', $edicao->id)
            ->where('turno', $turno->value)
            ->where('numero', $numero)
            ->where('projeto_id', '!=', $projeto->id)
            ->exists();

        if (! $ocupado) {
            EstandeProjeto::updateOrCreate(
                ['edicao_id' => $edicao->id, 'projeto_id' => $projeto->id],
                ['turno' => $turno, 'numero' => $numero, 'regra' => 'demo', 'manual' => true],
            );
        }
    }

    /** Ativa o avaliador em todos os turnos da agenda que ainda não terminaram. */
    private function ativar(User $avaliador, Edicao $edicao, JanelaTurnos $janela): int
    {
        $ativados = 0;

        foreach ($janela->ocorrencias() as $o) {
            if ($o['situacao'] === 'encerrado') {
                continue;
            }

            // A data é gravada com hora no SQLite: a busca precisa ser por dia.
            $existe = AvaliadorTurnoPresencial::where('edicao_id', $edicao->id)
                ->where('user_id', $avaliador->id)
                ->whereDate('dia', $o['dia'])
                ->where('turno', $o['turno'])
                ->exists();

            if (! $existe) {
                AvaliadorTurnoPresencial::create([
                    'edicao_id' => $edicao->id, 'user_id' => $avaliador->id, 'dia' => $o['dia'], 'turno' => $o['turno'],
                ]);
            }
            $ativados++;
        }

        return $ativados;
    }

    /** O checklist do avaliador sai deste catálogo; sem ele, o passo some. */
    private function semearItens(): int
    {
        if (ItemChecagemEstande::query()->exists()) {
            return 0;
        }

        foreach (self::ITENS as $ordem => [$nome, $descricao]) {
            ItemChecagemEstande::create(['nome' => $nome, 'descricao' => $descricao, 'ordem' => $ordem, 'ativo' => true]);
        }

        return count(self::ITENS);
    }
}
