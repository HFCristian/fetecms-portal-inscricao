<?php

namespace App\Services;

use App\Enums\TipoCredencial;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial → **Credenciais e Prêmios**.
 *
 * Credencial é a **vaga de premiação** que a feira tem para dar: a indicação a
 * uma feira nacional ou internacional, a bolsa de um parceiro, o prêmio de um
 * órgão. São poucas, têm dono e, no fim do evento, a organização precisa dizer
 * projeto a projeto quem recebeu o quê — hoje isso vive numa planilha que
 * ninguém audita.
 *
 * **Prêmio** cadastra-se do mesmo jeito e entra na mesma lista: o destaque, a
 * menção honrosa, o reconhecimento que se anuncia no palco sem entregar
 * crachá nenhum. A diferença é o `tipo` (`App\Enums\TipoCredencial`), e ela
 * importa num lugar só: no cerimonial, o card de **credenciais a separar**
 * conta o que tem objeto na mesa — as credenciais —, enquanto **premiado** (e
 * portanto a medalha) é quem recebeu qualquer uma das duas.
 *
 * Três coisas acontecem aqui: **cadastrar** o que existe para dar, **anexar**
 * cada credencial a um projeto e **gerar a lista de premiação**.
 *
 * Só **finalista** recebe credencial — é quem esteve na feira. E o teto de
 * vagas é conferido na atribuição: uma credencial com 3 vagas não vira 4 por
 * distração, mas uma **sem** número de vagas não trava nada, porque nem todo
 * órgão fecha o número antes do evento.
 */
class CredenciaisService
{
    /**
     * As credenciais da edição, com quem já as recebeu.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        return Credencial::where('edicao_id', $edicao->id)
            ->with(['projetos:id,titulo,categoria,area_id', 'projetos.area:id,nome'])
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get()
            ->map(fn (Credencial $c) => $this->resumo($c))
            ->all();
    }

    /**
     * Os projetos que podem receber credencial: os finalistas da lista
     * vigente, com o que cada um já recebeu.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatos(): array
    {
        $lista = ListaFinal::vigente();

        if ($lista === null) {
            return [];
        }

        $recebidas = DB::table('credencial_projeto')
            ->join('credenciais', 'credenciais.id', '=', 'credencial_projeto.credencial_id')
            ->select('credencial_projeto.projeto_id', 'credenciais.nome')
            ->get()
            ->groupBy('projeto_id');

        return Projeto::whereIn('id', $lista->projetos()->select('projetos.id'))
            ->with(['area:id,nome', 'instituicao:id,nome'])
            ->orderBy('titulo')
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'escola' => $p->instituicao?->nome,
                'credenciais' => $recebidas->get($p->id, collect())->pluck('nome')->all(),
            ])
            ->all();
    }

    /** @param  array<string, mixed>  $dados */
    public function criar(array $dados): Credencial
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'nome' => 'Nenhuma edição em curso para cadastrar credenciais.',
            ]);
        }

        return Credencial::create([
            'edicao_id' => $edicao->id,
            // Sem tipo, credencial: era o único que existia antes dos prêmios.
            'tipo' => TipoCredencial::tryFrom((string) ($dados['tipo'] ?? '')) ?? TipoCredencial::Credencial,
            'nome' => trim($dados['nome']),
            'orgao' => $dados['orgao'] ?? null,
            'descricao' => $dados['descricao'] ?? null,
            'vagas' => $dados['vagas'] ?? null,
            'ordem' => (int) ($dados['ordem'] ?? 0),
            'ativa' => $dados['ativa'] ?? true,
        ]);
    }

    /** @param  array<string, mixed>  $dados */
    public function atualizar(Credencial $credencial, array $dados): Credencial
    {
        $atribuidas = $credencial->projetos()->count();

        if (array_key_exists('vagas', $dados) && $dados['vagas'] !== null && $dados['vagas'] < $atribuidas) {
            throw ValidationException::withMessages([
                'vagas' => "Esta credencial já foi dada a {$atribuidas} projeto(s) — retire alguém antes de reduzir as vagas.",
            ]);
        }

        $credencial->fill(array_intersect_key($dados, array_flip([
            'tipo', 'nome', 'orgao', 'descricao', 'vagas', 'ordem', 'ativa',
        ])))->save();

        return $credencial->refresh();
    }

    public function excluir(Credencial $credencial): void
    {
        if ($credencial->projetos()->exists()) {
            throw ValidationException::withMessages([
                'credencial' => 'Esta credencial já foi dada a algum projeto — retire as atribuições antes de excluí-la.',
            ]);
        }

        $credencial->delete();
    }

    /**
     * Anexa a credencial a um projeto finalista.
     */
    public function atribuir(Credencial $credencial, Projeto $projeto, User $admin, ?string $observacao = null): Credencial
    {
        $this->garantirFinalista($projeto);

        if (! $credencial->ativa) {
            throw ValidationException::withMessages([
                'credencial' => 'Esta credencial está desativada.',
            ]);
        }

        if ($credencial->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto já recebeu esta credencial.',
            ]);
        }

        if (! $credencial->temVaga()) {
            throw ValidationException::withMessages([
                'projeto_id' => "As {$credencial->vagas} vaga(s) desta credencial já foram distribuídas.",
            ]);
        }

        $credencial->projetos()->attach($projeto->id, [
            'atribuida_por' => $admin->id,
            'atribuida_em' => now(),
            'observacao' => $observacao,
        ]);

        return $credencial->refresh();
    }

    public function retirar(Credencial $credencial, Projeto $projeto): Credencial
    {
        $credencial->projetos()->detach($projeto->id);

        return $credencial->refresh();
    }

    /**
     * A **lista de premiação** em TXT: uma seção por credencial, com os
     * projetos que a receberam.
     *
     * Sai no mesmo espírito da lista final — legível em voz alta na cerimônia,
     * com escola, alunos e orientador de cada projeto.
     */
    public function exportarTxt(): string
    {
        $edicao = Edicao::atual();
        $blocos = [];

        foreach ($this->credenciaisComProjetos() as $credencial) {
            $linhas = [
                '== ['.mb_strtoupper($credencial->tipo->label()).'] '
                    .mb_strtoupper($credencial->nome)
                    .($credencial->orgao ? ' — '.$credencial->orgao : ''),
            ];

            if ($credencial->projetos->isEmpty()) {
                $linhas[] = '(nenhum projeto contemplado)';
            }

            foreach ($credencial->projetos->sortBy(fn (Projeto $p) => $this->chave($p->titulo)) as $projeto) {
                $linhas[] = '';
                $linhas[] = $projeto->titulo;
                $linhas[] = $this->escola($projeto);

                foreach ($projeto->alunos->sortBy(fn ($a) => $this->chave($a->nome)) as $aluno) {
                    $linhas[] = $aluno->nome;
                }

                if ($projeto->user?->name) {
                    $linhas[] = $projeto->user->name.' - Orientador(a)';
                }

                if ($projeto->coorientador?->nome) {
                    $linhas[] = $projeto->coorientador->nome.' - Coorientador(a)';
                }
            }

            $blocos[] = implode("\n", $linhas);
        }

        $cabecalho = 'LISTA DE PREMIAÇÃO'.($edicao ? ' · '.$edicao->nome : '');

        return $cabecalho."\n\n".implode("\n\n", $blocos)."\n";
    }

    /**
     * A lista de premiação para a tela: uma entrada por credencial, com os
     * projetos e as pessoas de cada um.
     *
     * @return list<array<string, mixed>>
     */
    public function premiacao(): array
    {
        return $this->credenciaisComProjetos()
            ->map(fn (Credencial $c) => [
                'id' => $c->id,
                'tipo' => $c->tipo->value,
                'tipo_label' => $c->tipo->label(),
                'nome' => $c->nome,
                'orgao' => $c->orgao,
                'vagas' => $c->vagas,
                'projetos' => $c->projetos
                    ->sortBy(fn (Projeto $p) => $this->chave($p->titulo))
                    ->values()
                    ->map(fn (Projeto $p) => [
                        'id' => $p->id,
                        'titulo' => $p->titulo,
                        'categoria' => $p->categoria?->label(),
                        'area' => $p->area?->nome,
                        'escola' => $this->escola($p),
                        'alunos' => $p->alunos->sortBy(fn ($a) => $this->chave($a->nome))->pluck('nome')->values()->all(),
                        'orientador' => $p->user?->name,
                        'coorientador' => $p->coorientador?->nome,
                    ])->all(),
            ])
            ->all();
    }

    /** @return Collection<int, Credencial> */
    private function credenciaisComProjetos()
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return collect();
        }

        return Credencial::where('edicao_id', $edicao->id)
            ->where('ativa', true)
            ->with([
                'projetos.area:id,nome',
                'projetos.user:id,name',
                'projetos.alunos:id,projeto_id,nome',
                'projetos.coorientador:id,projeto_id,nome',
                'projetos.instituicao:id,nome,cidade_id',
                'projetos.instituicao.cidade:id,nome,estado_id',
                'projetos.instituicao.cidade.estado:id,uf',
            ])
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get();
    }

    /** @return array<string, mixed> */
    private function resumo(Credencial $credencial): array
    {
        $usadas = $credencial->projetos->count();

        return [
            'id' => $credencial->id,
            'tipo' => $credencial->tipo->value,
            'tipo_label' => $credencial->tipo->label(),
            'nome' => $credencial->nome,
            'orgao' => $credencial->orgao,
            'descricao' => $credencial->descricao,
            'vagas' => $credencial->vagas,
            'usadas' => $usadas,
            // Nulo = sem teto: a tela mostra "—" em vez de um número inventado.
            'disponiveis' => $credencial->vagas === null ? null : max(0, $credencial->vagas - $usadas),
            'ativa' => $credencial->ativa,
            'ordem' => $credencial->ordem,
            'projetos' => $credencial->projetos->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'observacao' => $p->pivot->observacao,
                'atribuida_em' => $p->pivot->atribuida_em,
            ])->all(),
        ];
    }

    private function garantirFinalista(Projeto $projeto): void
    {
        $lista = ListaFinal::vigente();

        if ($lista === null || ! $lista->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Só projetos finalistas recebem credencial ou prêmio.',
            ]);
        }
    }

    /** "Escola / Cidade - UF", como na lista final. */
    private function escola(Projeto $projeto): string
    {
        $instituicao = $projeto->instituicao;
        $local = trim(implode(' - ', array_filter([
            $instituicao?->cidade?->nome,
            $instituicao?->cidade?->estado?->uf,
        ])));

        $partes = array_filter([$instituicao?->nome, $local === '' ? null : $local]);

        return $partes === [] ? '—' : implode(' / ', $partes);
    }

    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
