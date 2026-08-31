<?php

namespace App\Console\Commands;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\TipoPessoaCredenciamento;
use App\Models\Aluno;
use App\Models\Area;
use App\Models\Coorientador;
use App\Models\DocumentoCredenciamento;
use App\Models\Edicao;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Monta os **dados de ensaio do balcão de credenciamento**: três projetos de
 * mentira, com equipe completa, reunidos numa **lista final de demonstração**.
 *
 * Por que uma lista à parte: finalista é quem está na lista final vigente, e a
 * edição só tem uma oficial. Publicar uma lista de treinamento encerraria a de
 * verdade. A lista demo (`listas_finais.demo`) corre em paralelo e só é
 * enxergada por quem liga o **modo de teste** na aba Credenciamento — que é
 * privilégio de conta demo. Com o modo desligado, o balcão volta à lista
 * oficial e o projeto de mentira desaparece.
 *
 * Rodar duas vezes não duplica nada: tudo é procurado antes de ser criado.
 *
 * O comando também semeia a **lista de documentos** de cada papel, mas **só se
 * o catálogo estiver vazio** — ela é global (vale para todas as edições) e a
 * organização costuma tê-la ajustada em Parametrização → Credenciamento.
 */
class SemearCredenciamentoDemo extends Command
{
    protected $signature = 'demo:credenciamento
        {--orientador=orientador.credenciamento@fetecms.test : e-mail do orientador demo dono dos projetos}
        {--senha=fetecms-demo : senha da conta, quando criada}
        {--projetos=3 : quantos projetos de demonstração montar}';

    protected $description = 'Cria a lista final de demonstração usada pelo modo de teste do credenciamento';

    /** Os projetos-exemplo: título, categoria e o tamanho da equipe. */
    private const EXEMPLOS = [
        ['Bioplástico a partir da casca de mandioca', Categoria::Fetecms, 3],
        ['Sensor de nível para caixas d\'água', Categoria::FetecJr, 2],
        ['Compostagem doméstica com resíduos de feira', Categoria::FetecmsFundect, 1],
    ];

    public function handle(): int
    {
        $edicao = Edicao::padrao() ?? Edicao::first();

        if ($edicao === null) {
            $this->error('Nenhuma edição cadastrada. Crie a edição da feira antes.');

            return self::FAILURE;
        }

        $quantos = max(1, min((int) $this->option('projetos'), count(self::EXEMPLOS)));

        DB::transaction(function () use ($edicao, $quantos) {
            $documentos = $this->semearDocumentos();
            $orientador = $this->orientadorDemo();
            $lista = $this->listaDemo($edicao);

            $projetos = [];

            foreach (array_slice(self::EXEMPLOS, 0, $quantos) as $i => [$titulo, $categoria, $alunos]) {
                $projeto = $this->projetoDemo($orientador, $edicao, $titulo, $categoria, $alunos, $i);
                $lista->projetos()->syncWithoutDetaching([$projeto->id => ['manual' => false]]);
                $projetos[] = $projeto;
            }

            $this->components->info("Orientador demo: {$orientador->email}");
            $this->components->info("Lista de demonstração: #{$lista->id} — {$lista->nome}");

            foreach ($projetos as $projeto) {
                $this->components->twoColumnDetail(
                    $projeto->titulo,
                    $projeto->alunos()->count().' aluno(s)',
                );
            }

            if ($documentos > 0) {
                $this->components->info("Catálogo de documentos vazio: {$documentos} documento(s) padrão criados.");
            }
        });

        $this->newLine();
        $this->line('Entre com um <options=bold>admin em modo demo</> (aba Administradores), abra');
        $this->line('<options=bold>Credenciamento</> e ligue o <options=bold>Modo de teste</>: o balcão passa a');
        $this->line('usar esta lista e ignora a janela do evento. Desligado, tudo volta ao oficial.');

        return self::SUCCESS;
    }

    /**
     * A lista paralela do ensaio. Ela é `vigente` na própria trilha demo, o que
     * não interfere na oficial — as duas convivem (ver `ListaFinal::vigente`).
     */
    private function listaDemo(Edicao $edicao): ListaFinal
    {
        $lista = ListaFinal::firstOrNew([
            'edicao_id' => $edicao->id,
            'demo' => true,
        ]);

        $lista->fill([
            'nome' => 'Lista de demonstração — credenciamento',
            'vigente' => true,
            'versao' => $lista->versao ?? 1,
        ]);
        $lista->save();

        return $lista;
    }

    /** O dono dos projetos de mentira. `is_demo` os tira do painel e do ranking. */
    private function orientadorDemo(): User
    {
        $user = User::firstOrNew(['email' => $this->option('orientador')]);
        $user->fill([
            'name' => $user->name ?? 'Orientador Credenciamento (demo)',
            'role' => Role::Orientador,
            'is_active' => true,
            'is_demo' => true,
        ]);
        $user->password = $user->exists ? $user->password : $this->option('senha');
        $user->save();

        $user->orientadorProfile()->firstOrCreate([], [
            'cpf' => '00000000353',
            'telefone' => '67900000001',
            'data_nascimento' => '1988-03-15',
        ]);

        return $user;
    }

    private function projetoDemo(
        User $orientador,
        Edicao $edicao,
        string $titulo,
        Categoria $categoria,
        int $alunos,
        int $indice,
    ): Projeto {
        $area = Area::orderBy('id')->skip($indice)->first() ?? Area::orderBy('id')->first();

        $projeto = Projeto::withoutGlobalScopes()->firstOrNew([
            'user_id' => $orientador->id,
            'titulo' => $titulo,
        ]);

        $projeto->fill([
            'edicao_id' => $edicao->id,
            'categoria' => $categoria,
            'area_id' => $area?->id,
            'subarea_id' => $area === null ? null : Subarea::where('area_id', $area->id)->value('id'),
            'instituicao_id' => $this->instituicao()?->id,
            'resumo' => 'Projeto fictício, criado para ensaiar o balcão de credenciamento.',
            'status' => ProjetoStatus::Submetido,
            'submitted_at' => $projeto->submitted_at ?? now(),
        ]);
        $projeto->save();

        $this->equipeDemo($projeto, $alunos);

        return $projeto;
    }

    /**
     * Alunos e coorientador do projeto-exemplo. As séries variam de propósito:
     * é o que deixa a ficha do balcão parecida com a de um projeto real.
     */
    private function equipeDemo(Projeto $projeto, int $quantos): void
    {
        // CPFs de teste com dígito verificador válido: o balcão não valida,
        // mas a coluna é obrigatória e única dentro do projeto.
        $series = [
            ['Ana Beatriz Souza', 'medio', '2_em', '00000000515'],
            ['Bruno Carvalho Lima', 'medio', '3_em', '00000000698'],
            ['Clara Nogueira Dias', 'fundamental_ii', '9_ef', '00000000779'],
        ];

        foreach (array_slice($series, 0, $quantos) as $i => [$nome, $modalidade, $ano, $cpf]) {
            Aluno::firstOrCreate(
                ['projeto_id' => $projeto->id, 'cpf' => $cpf],
                [
                    'nome' => $nome,
                    'email' => 'aluno'.($i + 1).'.'.$projeto->id.'@demo.fetecms.test',
                    'data_nascimento' => now()->subYears(17)->toDateString(),
                    'modalidade' => $modalidade,
                    'ano_escolar' => $ano,
                    'instituicao_id' => $projeto->instituicao_id,
                    'camiseta' => ['P', 'M', 'G'][$i % 3],
                ],
            );
        }

        Coorientador::firstOrCreate(
            ['projeto_id' => $projeto->id],
            [
                'nome' => 'Daniel Ferreira (coorientador demo)',
                'email' => 'coorientador.'.$projeto->id.'@demo.fetecms.test',
                'cpf' => '00000000434',
            ],
        );
    }

    /** Uma escola qualquer do catálogo — o balcão mostra escola e cidade na lista. */
    private function instituicao(): ?Instituicao
    {
        return Instituicao::orderBy('id')->first();
    }

    /**
     * Sem documentos cadastrados a ficha do balcão abre vazia e não há o que
     * conferir. Semeia um conjunto mínimo **apenas** quando o catálogo está
     * zerado — quem já configurou a lista de verdade não é tocado.
     *
     * @return int quantos documentos foram criados
     */
    private function semearDocumentos(): int
    {
        if (DocumentoCredenciamento::query()->exists()) {
            return 0;
        }

        $padrao = [
            [TipoPessoaCredenciamento::Aluno, ['Documento de identidade com foto', 'Autorização do responsável', 'Termo de imagem']],
            [TipoPessoaCredenciamento::Orientador, ['Documento de identidade com foto', 'Comprovante de vínculo com a escola']],
            [TipoPessoaCredenciamento::Coorientador, ['Documento de identidade com foto']],
        ];

        $criados = 0;

        foreach ($padrao as [$tipo, $nomes]) {
            foreach ($nomes as $ordem => $nome) {
                DocumentoCredenciamento::create([
                    'tipo_pessoa' => $tipo->value,
                    'nome' => $nome,
                    'ordem' => $ordem,
                    'ativo' => true,
                ]);
                $criados++;
            }
        }

        return $criados;
    }
}
