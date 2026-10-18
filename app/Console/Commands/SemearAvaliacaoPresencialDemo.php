<?php

namespace App\Console\Commands;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\TipoDocumento;
use App\Enums\Turno;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\AvaliadorLiberacaoPresencial;
use App\Models\ChecagemEstande;
use App\Models\Credenciamento;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\Instituicao;
use App\Models\ItemConferencia;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\Subarea;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Services\PdfService;
use App\Support\JanelaTurnos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Monta o **ensaio da avaliação presencial** (Sprint 172) para a conta
 * `avaliador@fetecms.test`: tudo que a aba "Avaliação presencial" e a
 * distribuição por turno precisam para serem testadas antes do evento.
 *
 * - o avaliador vira **conta demo**, **aceita** o presencial e fica **liberado**
 *   para o evento — a marca demo libera o "Modo de teste", que ignora o relógio
 *   do turno, e o aceite com a liberação o põem na distribuição do admin
 *   (também em modo de teste);
 * - três projetos de mentira entram na **lista final de demonstração** (a
 *   mesma do `demo:credenciamento`, que corre em paralelo à oficial), já
 *   **credenciados** e com o estande **checado** — as duas condições para um
 *   projeto ser distribuído —, no turno que a agenda tem em foco;
 * - cada projeto leva os **documentos** que a rubrica presencial pergunta —
 *   projeto de pesquisa, diário de bordo, pasta de referências, banner e, quando
 *   há pesquisa com pessoas, o TCLE/TALE —, em PDF fictício gerado na hora. Os
 *   três projetos passam por caminhos diferentes da rubrica (ver
 *   `database/data/demo/projetos-presenciais.php`);
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

    /** Onde mora o conteúdo dos projetos-exemplo e dos documentos deles. */
    private const EXEMPLOS = 'data/demo/projetos-presenciais.php';

    /** O disco dos anexos de projeto — o mesmo do upload (privado). */
    private const DISCO = 'local';

    /** Itens padrão da checagem, quando o catálogo está vazio. */
    private const ITENS = [
        ['Banner montado', 'O banner do projeto está exposto e legível.'],
        ['Diário de bordo', 'O caderno de campo da pesquisa está no estande.'],
        ['Protótipo ou experimento', 'O que o projeto construiu ou testou está à vista.'],
    ];

    public function handle(PdfService $pdf): int
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

        $exemplos = require database_path(self::EXEMPLOS);

        [$avaliador, $lista, $projetos, $itens] = DB::transaction(function () use ($edicao, $turno, $exemplos) {
            $itens = $this->semearItens();
            $avaliador = $this->avaliadorDemo();
            // Liberado para o evento inteiro, como a organização faria (Sprint 223).
            AvaliadorLiberacaoPresencial::firstOrCreate(['edicao_id' => $edicao->id, 'user_id' => $avaliador->id]);
            $orientador = $this->orientadorDemo();
            $lista = $this->listaDemo($edicao);
            $projetos = [];

            foreach ($exemplos as $i => $exemplo) {
                $projetos[] = $this->projetoPronto($orientador, $edicao, $lista, $exemplo, $turno, $i);
            }

            return [$avaliador, $lista, $projetos, $itens];
        });

        $this->components->info("Avaliador demo: {$avaliador->email} (aceitou o presencial, liberado para o evento, modo de teste liberado)");
        $this->components->info("Lista de demonstração: #{$lista->id} — {$lista->nome}");

        // Os PDFs saem depois da transação: o motor leva alguns segundos, e
        // segurar o banco por isso não protege nada — o arquivo é regravado
        // inteiro a cada execução.
        foreach ($projetos as $i => $projeto) {
            $documentos = $this->documentos($pdf, $projeto, $exemplos[$i]);
            $this->components->twoColumnDetail($projeto->titulo, "{$turno->label()} · credenciado e checado · {$documentos} documento(s)");
            $this->line("  <fg=gray>Cenário: {$exemplos[$i]['cenario']}</>");
        }

        if ($foco === null) {
            $this->components->warn('Sem agenda de turnos: defina as datas do evento e o horário dos turnos para avaliar fora do modo de teste.');
        }

        if ($itens > 0) {
            $this->components->info("Catálogo de itens da checagem vazio: {$itens} item(ns) padrão criados.");
        }

        $this->newLine();
        $this->line("Entre como <options=bold>{$avaliador->email}</>, abra <options=bold>Avaliação presencial</> e ligue o");
        $this->line('<options=bold>Modo de teste</>: os três estandes aparecem no mapa e na lista, sem esperar o turno.');
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
    /**
     * @param  array<string, mixed>  $exemplo
     */
    private function projetoPronto(User $orientador, Edicao $edicao, ListaFinal $lista, array $exemplo, Turno $turno, int $indice): Projeto
    {
        $area = Area::orderBy('id')->skip($indice)->first() ?? Area::orderBy('id')->first();

        $projeto = Projeto::withoutGlobalScopes()->firstOrNew(['user_id' => $orientador->id, 'titulo' => $exemplo['titulo']]);
        $projeto->fill([
            'edicao_id' => $edicao->id,
            'categoria' => $exemplo['categoria'],
            'area_id' => $area?->id,
            'subarea_id' => $area === null ? null : Subarea::where('area_id', $area->id)->value('id'),
            'instituicao_id' => Instituicao::orderBy('id')->value('id'),
            'resumo' => $exemplo['resumo'],
            'palavras_chave' => $exemplo['palavras_chave'],
            'status' => ProjetoStatus::Submetido,
            'submitted_at' => $projeto->submitted_at ?? now(),
        ]);
        $projeto->save();

        Aluno::firstOrCreate(
            ['projeto_id' => $projeto->id, 'cpf' => sprintf('000000009%02d', $indice)],
            [
                'nome' => $exemplo['aluno'],
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
     * Os documentos que a equipe levaria impressos, em PDF fictício anexado ao
     * projeto — é o que o avaliador abre na leitura do wizard. O que o cenário
     * não tem (o diário do projeto 3, o termo de ética dos que não pesquisam
     * com pessoas) fica de fora de propósito, e um documento que já existia e
     * saiu do cenário é apagado.
     *
     * @param  array<string, mixed>  $exemplo
     */
    private function documentos(PdfService $pdf, Projeto $projeto, array $exemplo): int
    {
        $dados = ['projeto' => [...$exemplo, 'categoria' => $exemplo['categoria']->label()]];
        $documentos = [
            ['pdf.demo.projeto-pesquisa', 'Projeto de Pesquisa.pdf', TipoDocumento::PlanoPesquisa, 'a4', true],
            ['pdf.demo.diario-bordo', 'Diário de Bordo.pdf', TipoDocumento::Anexo, 'a4', $exemplo['diario'] !== null],
            ['pdf.demo.pasta-referencias', 'Pasta de Referências.pdf', TipoDocumento::Anexo, 'a4', true],
            ['pdf.demo.banner', 'Banner.pdf', TipoDocumento::Anexo, 'a3', true],
            ['pdf.demo.termo-etica', $exemplo['etica']['nome'] ?? 'TCLE e TALE.pdf', TipoDocumento::TermoEtica, 'a4', $exemplo['etica'] !== null],
        ];
        $gerados = 0;

        foreach ($documentos as [$view, $nome, $tipo, $papel, $existe]) {
            $caminho = "projetos/{$projeto->id}/demo-".str($nome)->beforeLast('.')->slug().'.pdf';

            if (! $existe) {
                ProjetoDocumento::where('projeto_id', $projeto->id)->where('path', $caminho)->delete();
                Storage::disk(self::DISCO)->delete($caminho);

                continue;
            }

            $bytes = $pdf->render($view, $dados, $papel);
            Storage::disk(self::DISCO)->put($caminho, $bytes);

            ProjetoDocumento::updateOrCreate(
                ['projeto_id' => $projeto->id, 'path' => $caminho],
                [
                    'tipo' => $tipo, 'disk' => self::DISCO, 'nome_original' => $nome,
                    'mime' => 'application/pdf', 'tamanho_bytes' => strlen($bytes),
                ],
            );
            $gerados++;
        }

        return $gerados;
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

    /** O checklist do avaliador sai deste catálogo; sem ele, o passo some. */
    private function semearItens(): int
    {
        // Os itens do sistema (os termos) não contam: "vazio" é sem nenhum
        // item da organização marcado para a checagem.
        if (ItemConferencia::where('na_checagem', true)->whereNull('chave')->exists()) {
            return 0;
        }

        foreach (self::ITENS as $ordem => [$nome, $descricao]) {
            ItemConferencia::firstOrCreate(['nome' => $nome], [
                'descricao' => $descricao, 'abrangencia' => ItemConferencia::PROJETO,
                'na_checagem' => true, 'ordem' => $ordem, 'ativo' => true,
            ]);
        }

        return count(self::ITENS);
    }
}
