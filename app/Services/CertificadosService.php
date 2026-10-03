<?php

namespace App\Services;

use App\Enums\AbaAdmin;
use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\ContaTemporaria;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Support\PlanilhaXlsx;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Aba **Certificados** (Sprint 163): os dados que a organização precisa para
 * emitir certificados — o portal não emite o documento, entrega a planilha.
 *
 * Três pedidos, três recortes:
 *
 * - **Avaliadores por fase.** O certificado da fase online sai **antes** da fase
 *   presencial (há avaliador pedindo para processo seletivo com prazo), então
 *   as duas fases são contadas **separadas**: quantos projetos cada um avaliou
 *   online e quantos no estande. A carga horária a organização calcula a partir
 *   da quantidade. Conta a avaliação **concluída** — inclusive a que a
 *   organização desconsiderou depois, porque o trabalho aconteceu (a mesma regra
 *   do card de certificado do perfil). A avaliação feita *pela organização* não
 *   conta para ninguém: o avaliador dela é um admin.
 * - **Participantes.** Nome completo, CPF, função e projeto ou atividade de
 *   estudantes, orientadores e coorientadores (dos projetos submetidos ou só dos
 *   finalistas — a coluna *Finalista* diz qual é qual), da comissão especial, dos
 *   avaliadores de cada fase, dos voluntários (contas temporárias) e da
 *   organização (admins, com o CPF que o cadastro do admin passou a guardar).
 * - **Declaração nominal.** Os títulos dos projetos que um avaliador avaliou,
 *   para quem pede a declaração com a lista — em PDF, por pessoa, e numa planilha
 *   única com todas as avaliações.
 *
 * Tudo no recorte da edição em escopo (os projetos seguem o `EdicaoScope`) e sem
 * conta de ensaio (`is_demo`).
 */
class CertificadosService
{
    public const FASE_ONLINE = 'online';

    public const FASE_PRESENCIAL = 'presencial';

    public const FASE_TODAS = 'todas';

    /** Os grupos do CSV de participantes, na ordem da tela. */
    public const GRUPOS = [
        'estudantes' => 'Estudantes',
        'orientadores' => 'Orientadores',
        'coorientadores' => 'Coorientadores',
        'comissao' => 'Comissão especial de avaliação',
        'avaliadores_online' => 'Avaliadores — fase online',
        'avaliadores_presenciais' => 'Avaliadores — fase presencial',
        'voluntarios' => 'Voluntários (contas temporárias)',
        'organizacao' => 'Organização (administradores)',
    ];

    /** @return array<string, mixed> */
    public function opcoes(): array
    {
        return [
            'grupos' => collect(self::GRUPOS)->map(fn ($rotulo, $valor) => ['valor' => $valor, 'rotulo' => $rotulo])->values()->all(),
            'fases' => [
                ['valor' => self::FASE_ONLINE, 'rotulo' => 'Fase online'],
                ['valor' => self::FASE_PRESENCIAL, 'rotulo' => 'Fase presencial'],
                ['valor' => self::FASE_TODAS, 'rotulo' => 'As duas fases'],
            ],
            'tem_lista_final' => ListaFinal::vigente() !== null,
        ];
    }

    // --- Avaliadores ----------------------------------------------------------

    /**
     * Avaliadores com o número de avaliações concluídas em cada fase. `fase`
     * diz quem entra: quem avaliou online, quem avaliou presencialmente, ou
     * quem avaliou em qualquer uma.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function avaliadores(string $fase = self::FASE_ONLINE, string $busca = ''): Collection
    {
        $busca = mb_strtolower(trim($busca));

        return $this->queryAvaliadores()
            ->when($busca !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$busca.'%'])
                ->orWhereRaw('LOWER(email) LIKE ?', ['%'.$busca.'%'])))
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nome' => $u->name,
                'cpf' => $this->cpf($u->avaliadorProfile?->cpf),
                'email' => $u->email,
                'area' => $u->avaliadorProfile?->area?->nome,
                'subarea' => $u->avaliadorProfile?->subarea?->nome,
                'comissao_especial' => (bool) $u->avaliadorProfile?->comissao_especial,
                'online' => (int) $u->online_count,
                'presencial' => (int) $u->presencial_count,
            ])
            ->filter(fn (array $a) => match ($fase) {
                self::FASE_PRESENCIAL => $a['presencial'] > 0,
                self::FASE_TODAS => $a['online'] + $a['presencial'] > 0,
                default => $a['online'] > 0,
            })
            ->values();
    }

    /** @return array{conteudo: string, nome: string, tipo: string} */
    public function exportarAvaliadores(string $fase, string $formato): array
    {
        $linhas = $this->avaliadores($fase)->map(fn (array $a) => [
            $a['nome'], $a['cpf'], $a['email'],
            trim(($a['area'] ?? '').($a['subarea'] ? ' / '.$a['subarea'] : '')) ?: null,
            $a['online'], $a['presencial'],
        ])->all();

        return $this->arquivo(
            'certificados-avaliadores-'.$fase,
            ['Nome completo', 'CPF', 'E-mail', 'Área de avaliação', 'Projetos avaliados (fase online)', 'Projetos avaliados (fase presencial)'],
            $linhas,
            $formato,
        );
    }

    // --- Declaração nominal ----------------------------------------------------

    /**
     * Os projetos que este avaliador avaliou, por fase — o conteúdo da
     * declaração nominal.
     *
     * @return array<string, mixed>
     */
    public function projetosDoAvaliador(User $avaliador): array
    {
        abort_unless($avaliador->role === Role::Avaliador, 404, 'Avaliador não encontrado.');
        $avaliador->loadMissing('avaliadorProfile.area');

        $online = Avaliacao::query()
            ->where('avaliador_id', $avaliador->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->whereHas('projeto')
            ->with('projeto:id,titulo,area_id,categoria', 'projeto.area:id,nome')
            ->orderBy('concluida_em')
            ->get()
            ->map(fn (Avaliacao $a) => $this->linhaProjeto($a->projeto, $a->concluida_em));

        $presencial = AvaliacaoPresencial::query()
            ->where('avaliador_id', $avaliador->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->whereHas('projeto')
            ->with('projeto:id,titulo,area_id,categoria', 'projeto.area:id,nome')
            ->orderBy('concluida_em')
            ->get()
            ->map(fn (AvaliacaoPresencial $a) => $this->linhaProjeto($a->projeto, $a->concluida_em));

        return [
            'avaliador' => [
                'id' => $avaliador->id,
                'nome' => $avaliador->name,
                'cpf' => $this->cpf($avaliador->avaliadorProfile?->cpf),
                'email' => $avaliador->email,
                'area' => $avaliador->avaliadorProfile?->area?->nome,
            ],
            'edicao' => Edicao::atual()?->nome,
            'online' => $online->values()->all(),
            'presencial' => $presencial->values()->all(),
        ];
    }

    public function declaracaoPdf(User $avaliador): string
    {
        return app(PdfService::class)->render('pdf.declaracao-avaliador', $this->projetosDoAvaliador($avaliador) + [
            'gerado_em' => now()->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Uma linha por avaliação concluída, de todos os avaliadores: a lista que
     * responde de uma vez a vários pedidos de declaração.
     *
     * @return array{conteudo: string, nome: string, tipo: string}
     */
    public function exportarAvaliacoesNominais(string $fase, string $formato): array
    {
        $linhas = [];

        foreach ($this->avaliadores(self::FASE_TODAS) as $a) {
            $detalhe = $this->projetosDoAvaliador(User::find($a['id']));

            foreach ([self::FASE_ONLINE => 'Online', self::FASE_PRESENCIAL => 'Presencial'] as $chave => $rotulo) {
                if ($fase !== self::FASE_TODAS && $fase !== $chave) {
                    continue;
                }

                foreach ($detalhe[$chave] as $p) {
                    $linhas[] = [$a['nome'], $a['cpf'], $a['email'], $rotulo, $p['titulo'], $p['area'], $p['categoria'], $p['concluida_em']];
                }
            }
        }

        return $this->arquivo(
            'projetos-avaliados-por-avaliador-'.$fase,
            ['Avaliador(a)', 'CPF', 'E-mail', 'Fase', 'Projeto', 'Área', 'Categoria', 'Concluída em'],
            $linhas,
            $formato,
        );
    }

    // --- Participantes ---------------------------------------------------------

    /**
     * Nome, CPF, e-mail, função, projeto ou atividade de cada grupo escolhido.
     *
     * @param  list<string>  $grupos
     * @return list<array<string, mixed>>
     */
    public function participantes(array $grupos, string $escopo = 'finalistas'): array
    {
        $grupos = array_values(array_intersect(array_keys(self::GRUPOS), $grupos));

        if ($grupos === []) {
            throw ValidationException::withMessages(['grupos' => 'Escolha ao menos um grupo.']);
        }

        $edicao = Edicao::atual()?->nome ?? 'XVI FETECMS';
        $linhas = [];

        if (array_intersect($grupos, ['estudantes', 'orientadores', 'coorientadores']) !== []) {
            $finalistas = ListaFinal::vigente()?->projetos()->pluck('projetos.id')->all() ?? [];

            $projetos = Projeto::semDemo()
                ->whereIn('status', [ProjetoStatus::Submetido->value, ProjetoStatus::Aprovado->value, ProjetoStatus::Rejeitado->value])
                ->when($escopo === 'finalistas', fn (Builder $q) => $q->whereIn('id', $finalistas))
                ->with(['user.orientadorProfile', 'coorientador', 'alunos'])
                ->orderBy('titulo')
                ->get();

            foreach ($projetos as $p) {
                $finalista = in_array($p->id, $finalistas, true) ? 'Sim' : 'Não';

                if (in_array('estudantes', $grupos, true)) {
                    foreach ($p->alunos->sortBy(fn ($a) => mb_strtolower($a->nome)) as $a) {
                        $linhas[] = $this->linha($a->nome, $a->cpf, $a->email, 'Estudante', $p->titulo, $finalista);
                    }
                }

                if (in_array('orientadores', $grupos, true) && $p->user !== null) {
                    $linhas[] = $this->linha($p->user->name, $p->user->orientadorProfile?->cpf, $p->user->email, 'Orientador(a)', $p->titulo, $finalista);
                }

                if (in_array('coorientadores', $grupos, true) && $p->coorientador !== null) {
                    $c = $p->coorientador;
                    $linhas[] = $this->linha($c->nome, $c->cpf, $c->email, 'Coorientador(a)', $p->titulo, $finalista);
                }
            }
        }

        if (array_intersect($grupos, ['comissao', 'avaliadores_online', 'avaliadores_presenciais']) !== []) {
            foreach ($this->avaliadores(self::FASE_TODAS)->concat($this->comissaoSemAvaliacao()) as $a) {
                if (in_array('comissao', $grupos, true) && $a['comissao_especial']) {
                    $linhas[] = $this->linha($a['nome'], $a['cpf'], $a['email'], 'Comissão especial de avaliação', "Comissão especial de avaliação da {$edicao}");
                }
                if (in_array('avaliadores_online', $grupos, true) && $a['online'] > 0) {
                    $linhas[] = $this->linha($a['nome'], $a['cpf'], $a['email'], 'Avaliador(a) — fase online', "Avaliação de {$a['online']} projeto(s) na fase online");
                }
                if (in_array('avaliadores_presenciais', $grupos, true) && $a['presencial'] > 0) {
                    $linhas[] = $this->linha($a['nome'], $a['cpf'], $a['email'], 'Avaliador(a) — fase presencial', "Avaliação de {$a['presencial']} projeto(s) na fase presencial");
                }
            }
        }

        if (in_array('voluntarios', $grupos, true)) {
            ContaTemporaria::query()
                ->whereNull('removida_em')
                ->whereHas('user', fn (Builder $q) => $q->where('is_demo', false))
                ->with('user:id,name,email')
                ->get()
                ->sortBy(fn (ContaTemporaria $c) => mb_strtolower((string) $c->user?->name))
                ->each(function (ContaTemporaria $c) use (&$linhas) {
                    $setor = AbaAdmin::tryFrom((string) $c->setor)?->label() ?? $c->setor;
                    $linhas[] = $this->linha(
                        (string) $c->user?->name, $c->cpf, $c->user?->email, "Voluntário(a) — {$setor}",
                        $setor.($c->curso ? " · {$c->curso}" : ''),
                    );
                });
        }

        if (in_array('organizacao', $grupos, true)) {
            User::query()
                ->where('role', Role::Admin->value)
                ->where('is_active', true)
                ->where('is_demo', false)
                ->whereDoesntHave('contaTemporaria')
                ->orderBy('name')
                ->get()
                ->each(function (User $u) use (&$linhas, $edicao) {
                    $linhas[] = $this->linha($u->name, $u->cpf, $u->email, 'Comissão organizadora', "Organização da {$edicao}");
                });
        }

        return $linhas;
    }

    /**
     * @param  list<string>  $grupos
     * @return array{conteudo: string, nome: string, tipo: string}
     */
    public function exportarParticipantes(array $grupos, string $escopo, string $formato): array
    {
        $linhas = array_map(fn (array $l) => array_values($l), $this->participantes($grupos, $escopo));

        return $this->arquivo(
            'certificados-participantes',
            ['Nome completo', 'CPF', 'E-mail', 'Função', 'Projeto ou atividade', 'Finalista'],
            $linhas,
            $formato,
        );
    }

    // --- Internos ----------------------------------------------------------------

    /**
     * Avaliadores (não demo) com as concluídas de cada fase **desta edição** — o
     * `whereHas('projeto')` é o que traz o recorte da edição para a contagem.
     *
     * @return Builder<User>
     */
    private function queryAvaliadores(): Builder
    {
        $concluidas = fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value)->whereHas('projeto');

        return User::query()
            ->where('role', Role::Avaliador->value)
            ->where('is_demo', false)
            ->with(['avaliadorProfile.area:id,nome', 'avaliadorProfile.subarea:id,nome'])
            ->withCount([
                'avaliacoes as online_count' => $concluidas,
                'avaliacoesPresenciais as presencial_count' => $concluidas,
            ])
            ->orderBy('name');
    }

    /**
     * Membros da comissão especial que ainda não concluíram avaliação — eles
     * integram a comissão mesmo assim, e o certificado de comissão é deles.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function comissaoSemAvaliacao(): Collection
    {
        return $this->queryAvaliadores()
            ->whereHas('avaliadorProfile', fn (Builder $q) => $q->where('comissao_especial', true))
            ->get()
            ->filter(fn (User $u) => (int) $u->online_count + (int) $u->presencial_count === 0)
            ->map(fn (User $u) => [
                'nome' => $u->name,
                'cpf' => $this->cpf($u->avaliadorProfile?->cpf),
                'email' => $u->email,
                'comissao_especial' => true,
                'online' => 0,
                'presencial' => 0,
            ])
            ->values();
    }

    /** @return array<string, mixed> */
    private function linhaProjeto(?Projeto $projeto, $concluidaEm): array
    {
        return [
            'projeto_id' => $projeto?->id,
            'titulo' => $projeto?->titulo,
            'area' => $projeto?->area?->nome,
            'categoria' => $projeto?->categoria?->label(),
            'concluida_em' => $concluidaEm?->format('d/m/Y'),
        ];
    }

    /** @return array<string, mixed> */
    private function linha(string $nome, ?string $cpf, ?string $email, string $funcao, ?string $atividade, string $finalista = '—'): array
    {
        return [
            'nome' => $nome,
            'cpf' => $this->cpf($cpf),
            'email' => $email,
            'funcao' => $funcao,
            'atividade' => $atividade,
            'finalista' => $finalista,
        ];
    }

    private function cpf(?string $cpf): ?string
    {
        $d = preg_replace('/\D/', '', (string) $cpf);

        return strlen((string) $d) === 11
            ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d)
            : null;
    }

    /**
     * @param  list<string>  $cabecalho
     * @param  list<list<mixed>>  $linhas
     * @return array{conteudo: string, nome: string, tipo: string}
     */
    private function arquivo(string $base, array $cabecalho, array $linhas, string $formato): array
    {
        $base .= '-'.now()->format('Y-m-d');

        if ($formato === 'xlsx') {
            return [
                'conteudo' => PlanilhaXlsx::gerar('Certificados', $cabecalho, $linhas),
                'nome' => $base.'.xlsx',
                'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}");
        fputcsv($saida, $cabecalho, ';', '"', '');
        foreach ($linhas as $linha) {
            fputcsv($saida, array_map(fn ($v) => $v === null ? '' : (string) $v, $linha), ';', '"', '');
        }
        rewind($saida);
        $csv = (string) stream_get_contents($saida);
        fclose($saida);

        return ['conteudo' => $csv, 'nome' => $base.'.csv', 'tipo' => 'text/csv; charset=UTF-8'];
    }
}
