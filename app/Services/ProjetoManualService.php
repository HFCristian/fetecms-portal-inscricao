<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\TipoRegistro;
use App\Models\Aluno;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Instituicao;
use App\Models\ListaFinal;
use App\Models\OrientadorProfile;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * **Cadastro manual de projeto** pelo admin (Sprint 157).
 *
 * Há projetos que vão à fase presencial sem ter passado pela inscrição: a vaga
 * veio de uma **feira afiliada** (credencial), ou a organização recebeu a
 * equipe só com os nomes. Pedir a inscrição agora não cabe no calendário, e
 * inserir direto no banco leva tempo e não deixa rastro. Esta tela cadastra o
 * projeto inteiro — título, instituição, área, categoria, orientador,
 * coorientador e estudantes — e o põe **na lista final vigente**, que é o que o
 * faz existir para o credenciamento, o mapa, os crachás e a avaliação
 * presencial. Opcionalmente, ele já nasce **credenciado** no balcão.
 *
 * Três decisões de desenho:
 *
 * - **Origem**: *finalista* (a equipe que a organização decidiu levar) ou
 *   *credencial de feira afiliada* — esta grava `feira_afiliada`, o nome da
 *   feira e o número da credencial, os mesmos campos que a inscrição usa.
 * - **Fora da avaliação online** (`projetos.cadastro_manual`): a fase online já
 *   acabou para ele, e distribuí-lo a avaliador — ou contá-lo como "0
 *   avaliações" — cobraria uma etapa que não existe ({@see Projeto::avaliacaoOnline()}).
 * - **O orientador é uma conta**: o projeto precisa de dono. Escolhe-se uma
 *   conta de orientador que já existe ou cria-se uma nova, ativa e com senha
 *   aleatória — a pessoa entra depois por "Esqueci a senha" e completa o
 *   perfil. O e-mail de um avaliador ou de um admin é recusado (exclusão
 *   mútua entre orientador e avaliador).
 *
 * CPF e e-mail de alunos e coorientador são **opcionais** aqui: a organização
 * costuma ter só o nome. O que for informado é validado.
 *
 * Tudo é escape do edital, então tudo pede **justificativa** e vira registro em
 * Registros → Projetos; a inclusão na lista final entra também em Registros →
 * Lista final, pelo caminho de sempre ({@see ListaFinalService::adicionarProjeto()}).
 */
class ProjetoManualService
{
    public function __construct(
        private readonly ListaFinalService $listas,
        private readonly CredenciamentoService $credenciamentos,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /**
     * Os projetos cadastrados à mão nesta edição.
     *
     * @return array<string, mixed>
     */
    public function listar(): array
    {
        $vigente = ListaFinal::vigente();
        $naLista = $vigente?->projetos()->pluck('projetos.id')->all() ?? [];

        $projetos = Projeto::query()
            ->where('cadastro_manual', true)
            ->with(['user:id,name,email', 'area:id,nome', 'instituicao:id,nome', 'alunos:id,projeto_id,nome', 'coorientador:id,projeto_id,nome', 'credenciamento'])
            ->orderBy('titulo')
            ->get();

        return [
            'lista' => $vigente === null ? null : ['id' => $vigente->id, 'nome' => $vigente->nome, 'versao' => (int) $vigente->versao],
            'projetos' => $projetos->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'instituicao' => $p->instituicao?->nome,
                'orientador' => $p->user?->name,
                'orientador_email' => $p->user?->email,
                'coorientador' => $p->coorientador?->nome,
                'alunos' => $p->alunos->pluck('nome')->all(),
                'origem' => $p->feira_afiliada ? 'credencial' : 'finalista',
                'origem_label' => $this->origemLabel($p),
                'finalista' => in_array($p->id, $naLista, true),
                'credenciado' => (bool) $p->credenciamento?->finalizado_em,
                'criado_em' => $p->created_at?->format('d/m/Y H:i'),
            ])->all(),
        ];
    }

    /**
     * O projeto como o formulário de edição o quer.
     *
     * @return array<string, mixed>
     */
    public function detalhe(Projeto $projeto): array
    {
        $this->garantirManual($projeto);
        $projeto->load(['user', 'area', 'subarea', 'instituicao', 'alunos', 'coorientador', 'credenciamento']);

        return [
            'id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'categoria' => $projeto->categoria?->value,
            'instituicao' => $projeto->instituicao ? ['id' => $projeto->instituicao->id, 'nome' => $projeto->instituicao->nome] : null,
            'area_id' => $projeto->area_id,
            'subarea' => $projeto->subarea ? ['id' => $projeto->subarea->id, 'nome' => $projeto->subarea->nome] : null,
            'origem' => $projeto->feira_afiliada ? 'credencial' : 'finalista',
            'feira_afiliada_nome' => $projeto->feira_afiliada_nome,
            'numero_credencial' => $projeto->numero_credencial,
            'orientador' => $projeto->user ? ['id' => $projeto->user->id, 'nome' => $projeto->user->name, 'email' => $projeto->user->email] : null,
            'coorientador' => $projeto->coorientador ? [
                'nome' => $projeto->coorientador->nome,
                'email' => $projeto->coorientador->email,
                'cpf' => $projeto->coorientador->cpf,
            ] : null,
            'alunos' => $projeto->alunos->map(fn (Aluno $a) => [
                'id' => $a->id, 'nome' => $a->nome, 'email' => $a->email, 'cpf' => $a->cpf,
            ])->all(),
            'credenciado' => (bool) $projeto->credenciamento?->finalizado_em,
        ];
    }

    /**
     * Cadastra o projeto, põe na lista final vigente e — se pedido — já o
     * credencia. Tudo numa transação: um projeto pela metade (sem equipe, fora
     * da lista) seria pior do que nenhum.
     *
     * @param  array<string, mixed>  $dados
     */
    public function criar(array $dados, User $admin): Projeto
    {
        $lista = ListaFinal::vigente();

        if ($lista === null) {
            throw ValidationException::withMessages([
                'lista' => 'Não há lista final oficial publicada nesta edição. Publique a lista antes de cadastrar projetos à mão — é ela que define quem vai ao evento.',
            ]);
        }

        $categoria = Categoria::from($dados['categoria']);
        $this->conferirEquipe($dados, $categoria);
        $justificativa = trim($dados['justificativa']);

        return DB::transaction(function () use ($dados, $admin, $lista, $justificativa) {
            $orientador = $this->orientador($dados['orientador'], (int) $dados['instituicao_id']);

            $projeto = Projeto::create($this->camposDoProjeto($dados) + [
                'user_id' => $orientador->id,
                'edicao_id' => $lista->edicao_id ?? Edicao::atual()?->id,
                'status' => ProjetoStatus::Submetido,
                'submitted_at' => now(),
                'cadastro_manual' => true,
            ]);

            $this->gravarEquipe($projeto, $dados);

            $this->registros->projetoManual(
                TipoRegistro::ProjetoCadastroManual, $projeto, $admin, $justificativa,
                'origem: '.$this->origemLabel($projeto),
            );

            $this->listas->adicionarProjeto($lista, $projeto, $admin, $justificativa);

            if (! empty($dados['credenciado'])) {
                $this->credenciamentos->registrarNoCadastroManual(
                    $projeto, $admin, 'Credenciado no cadastro manual do projeto: '.$justificativa,
                );
            }

            return $projeto->fresh(['user', 'alunos', 'coorientador']);
        });
    }

    /**
     * Corrige o cadastro. Os alunos chegam com o `id` dos que já existem: quem
     * não vier é removido, quem vier sem id é acrescentado.
     *
     * @param  array<string, mixed>  $dados
     */
    public function atualizar(Projeto $projeto, array $dados, User $admin): Projeto
    {
        $this->garantirManual($projeto);

        $categoria = Categoria::from($dados['categoria']);
        $this->conferirEquipe($dados, $categoria);
        $justificativa = trim($dados['justificativa']);

        return DB::transaction(function () use ($projeto, $dados, $admin, $justificativa) {
            $antes = $this->retrato($projeto);

            $orientador = $this->orientador($dados['orientador'], (int) $dados['instituicao_id']);
            $projeto->update($this->camposDoProjeto($dados) + ['user_id' => $orientador->id]);
            $this->gravarEquipe($projeto, $dados);

            $depois = $this->retrato($projeto->fresh(['user', 'alunos', 'coorientador']));
            $mudou = array_keys(array_filter($depois, fn ($v, $k) => ($antes[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));

            if ($mudou !== []) {
                $this->registros->projetoManual(
                    TipoRegistro::ProjetoManualAlterado, $projeto, $admin, $justificativa,
                    'alterado: '.implode(', ', $mudou),
                );
            }

            if (! empty($dados['credenciado'])) {
                $this->credenciamentos->registrarNoCadastroManual(
                    $projeto, $admin, 'Credenciado no cadastro manual do projeto: '.$justificativa,
                );
            }

            return $projeto->fresh(['user', 'alunos', 'coorientador']);
        });
    }

    /**
     * Tira o projeto da lista final e o apaga (soft delete). É para o cadastro
     * que não devia existir — duplicado, equipe que desistiu.
     */
    public function excluir(Projeto $projeto, User $admin, string $justificativa): void
    {
        $this->garantirManual($projeto);
        $justificativa = trim($justificativa);

        DB::transaction(function () use ($projeto, $admin, $justificativa) {
            $vigente = ListaFinal::vigente();

            if ($vigente?->projetos()->whereKey($projeto->id)->exists()) {
                $this->listas->removerProjeto($vigente, $projeto, $admin, $justificativa);
            }

            $this->registros->projetoManual(TipoRegistro::ProjetoManualExcluido, $projeto, $admin, $justificativa);
            $projeto->delete();
        });
    }

    private function garantirManual(Projeto $projeto): void
    {
        abort_unless($projeto->cadastro_manual, 404, 'Projeto não encontrado.');
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function camposDoProjeto(array $dados): array
    {
        $instituicao = Instituicao::with('cidade')->find($dados['instituicao_id']);
        $areaId = (int) $dados['area_id'];
        $subareaId = $dados['subarea_id'] ?? null;

        if ($subareaId !== null && ! Subarea::whereKey($subareaId)->where('area_id', $areaId)->exists()) {
            throw ValidationException::withMessages(['subarea_id' => 'A subárea não pertence à área escolhida.']);
        }

        $credencial = ($dados['origem'] ?? 'finalista') === 'credencial';

        return [
            'titulo' => trim($dados['titulo']),
            'categoria' => $dados['categoria'],
            'instituicao_id' => $instituicao?->id,
            'area_id' => $areaId,
            'subarea_id' => $subareaId,
            // A cidade do projeto é a da escola — é o que a lista final imprime.
            'pais' => 'BR',
            'estado_id' => $instituicao?->cidade?->estado_id,
            'cidade_id' => $instituicao?->cidade_id,
            'feira_afiliada' => $credencial,
            'feira_afiliada_nome' => $credencial ? trim((string) ($dados['feira_afiliada_nome'] ?? '')) : null,
            'numero_credencial' => $credencial ? (trim((string) ($dados['numero_credencial'] ?? '')) ?: null) : null,
        ];
    }

    /**
     * Quantidade por categoria e repetições dentro da equipe (o banco recusa o
     * mesmo e-mail ou CPF duas vezes no projeto, e a mensagem dele não ajuda).
     *
     * @param  array<string, mixed>  $dados
     */
    private function conferirEquipe(array $dados, Categoria $categoria): void
    {
        $alunos = $dados['alunos'] ?? [];
        // O cadastro manual é escape do edital: vale o teto mais largo da
        // categoria (o PICTEC da FETECMS libera o quarto aluno).
        $max = $categoria->maxAlunos(pictecMs: true);

        if (count($alunos) > $max) {
            throw ValidationException::withMessages([
                'alunos' => "A categoria {$categoria->label()} permite até {$max} estudantes.",
            ]);
        }

        foreach (['email' => 'e-mail', 'cpf' => 'CPF'] as $campo => $rotulo) {
            $valores = array_filter(array_map(fn ($a) => $a[$campo] ?? null, $alunos));

            if (count($valores) !== count(array_unique(array_map('mb_strtolower', $valores)))) {
                throw ValidationException::withMessages([
                    'alunos' => "Há dois estudantes com o mesmo {$rotulo}.",
                ]);
            }
        }
    }

    /**
     * A conta dona do projeto: a escolhida, a que já tem aquele e-mail, ou uma
     * nova. Nova nasce ativa e com senha aleatória — a pessoa entra por
     * "Esqueci a senha".
     *
     * @param  array<string, mixed>  $dados
     */
    private function orientador(array $dados, int $instituicaoId): User
    {
        if (! empty($dados['user_id'])) {
            $user = User::find($dados['user_id']);

            if ($user === null || $user->role !== Role::Orientador) {
                throw ValidationException::withMessages(['orientador.user_id' => 'Escolha uma conta de orientador.']);
            }

            return $user;
        }

        $email = trim((string) $dados['email']);
        $existente = User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

        if ($existente !== null) {
            if ($existente->role !== Role::Orientador) {
                throw ValidationException::withMessages([
                    'orientador.email' => $existente->role === Role::Avaliador
                        ? 'Este e-mail é de um avaliador — quem avalia não pode orientar projeto.'
                        : 'Este e-mail é de uma conta de administração.',
                ]);
            }

            return $existente;
        }

        $cpf = $dados['cpf'] ?? null;

        if ($cpf !== null) {
            if (OrientadorProfile::where('cpf', $cpf)->exists()) {
                throw ValidationException::withMessages([
                    'orientador.cpf' => 'Já existe um orientador com este CPF — escolha a conta dele em vez de criar outra.',
                ]);
            }

            if (AvaliadorProfile::where('cpf', $cpf)->exists()) {
                throw ValidationException::withMessages([
                    'orientador.cpf' => 'Este CPF é de um avaliador — quem avalia não pode orientar projeto.',
                ]);
            }
        }

        $user = User::create([
            'name' => trim((string) $dados['nome']),
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'role' => Role::Orientador,
            'is_active' => true,
        ]);

        $user->orientadorProfile()->create([
            'cpf' => $cpf,
            'telefone' => $dados['telefone'] ?? null,
            'instituicao_id' => $instituicaoId ?: null,
            'pais' => 'BR',
        ]);

        return $user;
    }

    /** @param  array<string, mixed>  $dados */
    private function gravarEquipe(Projeto $projeto, array $dados): void
    {
        $alunos = $dados['alunos'] ?? [];

        // Quem saiu da equipe sai primeiro: se o substituto vier com o mesmo
        // e-mail ou CPF, a chave única do projeto não pode encontrar os dois.
        $ficam = array_filter(array_map(fn ($a) => $a['id'] ?? null, $alunos));
        $projeto->alunos()->whereNotIn('id', $ficam)->delete();

        foreach ($alunos as $aluno) {
            $campos = [
                'nome' => trim((string) $aluno['nome']),
                'email' => ($aluno['email'] ?? null) ?: null,
                'cpf' => ($aluno['cpf'] ?? null) ?: null,
                'instituicao_id' => $projeto->instituicao_id,
            ];

            $existente = ! empty($aluno['id']) ? $projeto->alunos()->whereKey($aluno['id'])->first() : null;

            $existente !== null ? $existente->update($campos) : $projeto->alunos()->create($campos);
        }

        $coorientador = $dados['coorientador'] ?? null;

        if (empty($coorientador['nome'])) {
            $projeto->coorientador()->delete();

            return;
        }

        $projeto->coorientador()->updateOrCreate([], [
            'nome' => trim((string) $coorientador['nome']),
            'email' => ($coorientador['email'] ?? null) ?: null,
            'cpf' => ($coorientador['cpf'] ?? null) ?: null,
        ]);
    }

    /**
     * O que muda entre um salvamento e outro, para o registro dizer quais
     * campos foram alterados.
     *
     * @return array<string, mixed>
     */
    private function retrato(Projeto $projeto): array
    {
        $projeto->loadMissing(['alunos', 'coorientador']);

        return [
            'título' => $projeto->titulo,
            'categoria' => $projeto->categoria?->value,
            'instituição' => $projeto->instituicao_id,
            'área' => $projeto->area_id,
            'subárea' => $projeto->subarea_id,
            'origem' => [$projeto->feira_afiliada, $projeto->feira_afiliada_nome, $projeto->numero_credencial],
            'orientador' => $projeto->user_id,
            'coorientador' => $projeto->coorientador ? [$projeto->coorientador->nome, $projeto->coorientador->email, $projeto->coorientador->cpf] : null,
            'estudantes' => $projeto->alunos->sortBy('id')->map(fn (Aluno $a) => [$a->nome, $a->email, $a->cpf])->values()->all(),
        ];
    }

    private function origemLabel(Projeto $projeto): string
    {
        if (! $projeto->feira_afiliada) {
            return 'Finalista';
        }

        return 'Credencial de feira afiliada'
            .($projeto->feira_afiliada_nome ? ' · '.$projeto->feira_afiliada_nome : '')
            .($projeto->numero_credencial ? ' · nº '.$projeto->numero_credencial : '');
    }
}
