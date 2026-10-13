<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusRecomendacao;
use App\Models\Credencial;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\RecomendacaoPremiacao;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recomendações do **comitê especial** para a premiação (Sprint 193).
 *
 * O avaliador da comissão especial circula pela feira, vê os estandes e é
 * quem melhor sabe que projeto merece qual credencial. Pela aba *Comitê
 * especial* da tela dele, recomenda finalistas para as credenciais e os
 * prêmios ativos — **sem teto**, nem por avaliador nem por credencial: a
 * recomendação é opinião, e cortar o número cortaria justamente o caso
 * disputado, em que três pessoas apontam três projetos.
 *
 * A organização **aprova ou rejeita**, e a decisão **só marca**: anexar a
 * credencial ao projeto continua sendo o passo de Credenciais e Prêmios (ou
 * da Premiação do comitê), que tem as regras de vagas. Assim duas
 * recomendações aprovadas para uma credencial de uma vaga não estouram nada —
 * a organização escolhe quem leva na hora de anexar.
 */
class RecomendacaoPremiacaoService
{
    public function __construct(private readonly CredenciaisService $credenciais) {}

    /** Só avaliador ativo da comissão especial recomenda. */
    public function podeRecomendar(User $user): bool
    {
        return $user->role === Role::Avaliador
            && $user->is_active
            && (bool) $user->avaliadorProfile?->comissao_especial;
    }

    /**
     * A aba do avaliador: as credenciais ativas, os finalistas e o que ele já
     * recomendou.
     *
     * @return array<string, mixed>
     */
    public function painel(User $avaliador): array
    {
        $credenciais = $this->credenciaisAtivas()->get();

        return [
            'credenciais' => $credenciais->map(fn (Credencial $c) => [
                'id' => $c->id,
                'tipo' => $c->tipo->value,
                'tipo_label' => $c->tipo->label(),
                'nome' => $c->nome,
                'orgao' => $c->orgao,
                'descricao' => $c->descricao,
                'informacoes' => $c->informacoes,
                'vagas' => $c->vagas,
            ])->values()->all(),
            // O que o projeto já recebeu fica de fora: é decisão da
            // organização, e quem recomenda não precisa dela para opinar.
            'candidatos' => array_map(fn (array $p) => array_intersect_key($p, array_flip(['id', 'titulo', 'categoria', 'area', 'escola'])),
                $this->credenciais->candidatos()),
            'recomendacoes' => RecomendacaoPremiacao::where('avaliador_id', $avaliador->id)
                ->whereIn('credencial_id', $credenciais->pluck('id'))
                ->with('projeto:id,titulo,categoria,area_id', 'projeto.area:id,nome')
                ->latest('id')
                ->get()
                ->map(fn (RecomendacaoPremiacao $r) => $this->linhaDoAvaliador($r))
                ->all(),
        ];
    }

    public function recomendar(User $avaliador, Credencial $credencial, Projeto $projeto, ?string $justificativa): RecomendacaoPremiacao
    {
        if (! $credencial->ativa || $credencial->edicao_id !== Edicao::atual()?->id) {
            throw ValidationException::withMessages([
                'credencial_id' => 'Esta credencial não está disponível para recomendação.',
            ]);
        }

        $this->credenciais->garantirFinalista($projeto);

        $existe = RecomendacaoPremiacao::where('credencial_id', $credencial->id)
            ->where('projeto_id', $projeto->id)
            ->where('avaliador_id', $avaliador->id)
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Você já recomendou este projeto para esta credencial.',
            ]);
        }

        $justificativa = trim((string) $justificativa);

        return RecomendacaoPremiacao::create([
            'credencial_id' => $credencial->id,
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
            'justificativa' => $justificativa === '' ? null : $justificativa,
            'status' => StatusRecomendacao::Pendente,
        ]);
    }

    /** O avaliador desiste da própria recomendação enquanto ninguém decidiu. */
    public function retirar(User $avaliador, RecomendacaoPremiacao $recomendacao): void
    {
        abort_if($recomendacao->avaliador_id !== $avaliador->id, 404);

        if ($recomendacao->status !== StatusRecomendacao::Pendente) {
            throw ValidationException::withMessages([
                'recomendacao' => 'A organização já decidiu esta recomendação — ela não pode mais ser retirada.',
            ]);
        }

        $recomendacao->delete();
    }

    /**
     * As recomendações das credenciais da edição, para a organização decidir.
     *
     * @return array{recomendacoes: list<array<string, mixed>>, contagem: array<string, int>}
     */
    public function listar(?StatusRecomendacao $status = null): array
    {
        $base = RecomendacaoPremiacao::query()
            ->whereIn('credencial_id', $this->credenciaisDaEdicao()->select('id'));

        $contagem = (clone $base)->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n);

        $premiados = DB::table('credencial_projeto')->get(['credencial_id', 'projeto_id'])
            ->mapWithKeys(fn ($l) => [$l->credencial_id.'|'.$l->projeto_id => true]);

        $recomendacoes = $base
            ->when($status !== null, fn ($q) => $q->where('status', $status->value))
            ->with([
                'credencial:id,nome,tipo,orgao,vagas',
                'projeto:id,titulo,categoria,area_id', 'projeto.area:id,nome',
                'avaliador:id,name',
                'decididaPor:id,name',
            ])
            ->orderByRaw("CASE status WHEN 'pendente' THEN 0 ELSE 1 END")
            ->latest('id')
            ->get()
            ->map(fn (RecomendacaoPremiacao $r) => $this->linhaDoAdmin($r, isset($premiados[$r->credencial_id.'|'.$r->projeto_id])))
            ->all();

        return [
            'recomendacoes' => $recomendacoes,
            'contagem' => [
                'pendente' => $contagem[StatusRecomendacao::Pendente->value] ?? 0,
                'aprovada' => $contagem[StatusRecomendacao::Aprovada->value] ?? 0,
                'rejeitada' => $contagem[StatusRecomendacao::Rejeitada->value] ?? 0,
            ],
        ];
    }

    /**
     * Aprova ou rejeita. A decisão pode ser trocada depois (a organização
     * reconsidera); fica sempre o nome de quem decidiu por último.
     */
    public function decidir(RecomendacaoPremiacao $recomendacao, StatusRecomendacao $status, User $admin): RecomendacaoPremiacao
    {
        if ($status === StatusRecomendacao::Pendente) {
            throw ValidationException::withMessages(['status' => 'Escolha aprovar ou rejeitar.']);
        }

        abort_unless($this->credenciaisDaEdicao()->whereKey($recomendacao->credencial_id)->exists(), 404);

        $recomendacao->forceFill([
            'status' => $status,
            'decidida_por' => $admin->id,
            'decidida_em' => now(),
        ])->save();

        return $recomendacao->refresh();
    }

    /** @return Builder<Credencial> */
    private function credenciaisDaEdicao(): Builder
    {
        return Credencial::query()->where('edicao_id', Edicao::atual()?->id ?? 0);
    }

    /** @return Builder<Credencial> */
    private function credenciaisAtivas(): Builder
    {
        return $this->credenciaisDaEdicao()->where('ativa', true)->orderBy('ordem')->orderBy('nome');
    }

    /** @return array<string, mixed> */
    private function linhaDoAvaliador(RecomendacaoPremiacao $r): array
    {
        return [
            'id' => $r->id,
            'credencial_id' => $r->credencial_id,
            'projeto_id' => $r->projeto_id,
            'projeto' => $r->projeto?->titulo,
            'categoria' => $r->projeto?->categoria?->label(),
            'area' => $r->projeto?->area?->nome,
            'justificativa' => $r->justificativa,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'criada_em' => $r->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function linhaDoAdmin(RecomendacaoPremiacao $r, bool $jaPremiado): array
    {
        return $this->linhaDoAvaliador($r) + [
            'credencial' => $r->credencial?->nome,
            'credencial_tipo' => $r->credencial?->tipo?->value,
            'credencial_tipo_label' => $r->credencial?->tipo?->label(),
            'avaliador' => $r->avaliador?->name,
            'decidida_por' => $r->decididaPor?->name,
            'decidida_em' => $r->decidida_em?->toIso8601String(),
            // O projeto já levou esta credencial (anexada à parte): a
            // aprovação vira só registro de que a recomendação foi atendida.
            'ja_premiado' => $jaPremiado,
        ];
    }
}
