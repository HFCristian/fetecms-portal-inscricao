<?php

namespace App\Services;

use App\Enums\SituacaoDocumento;
use App\Enums\SituacaoTermoImagem;
use App\Enums\TipoDocumento;
use App\Enums\TipoPessoaCredenciamento;
use App\Models\Aluno;
use App\Models\ItemConferencia;
use App\Models\ProjetoDocumento;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quem da equipe de cada projeto já tem o **termo de uso de imagem** (Sprint
 * 174), em lote.
 *
 * O termo é enviado pelo orientador na aba Documentos, em um ou mais arquivos,
 * cada um valendo para os estudantes que ele marcou. A mesma pergunta — "quem
 * ainda falta?" — é feita na aba Documentos, na ficha do credenciamento e no
 * Mapa do Evento, e por isso a resposta mora aqui, uma consulta por fato e não
 * uma por projeto: o mapa a refaz a cada 30 segundos para 230 estandes.
 *
 * A cobertura é sempre calculada sobre a equipe **atual**: estudante que saiu
 * leva o vínculo junto (a chave apaga em cascata), e estudante que entrou
 * depois do envio aparece como faltando — que é o que de fato acontece.
 */
class TermosImagemService
{
    /**
     * A cobertura de cada projeto, por id.
     *
     * Com `$corte`, só contam os arquivos enviados até aquele instante — é o
     * que deixa o seletor de dia do mapa mostrar o fim de um dia anterior.
     *
     * @param  iterable<int>  $projetoIds
     * @return array<int, array<string, mixed>>
     */
    public function cobertura(iterable $projetoIds, ?CarbonInterface $corte = null): array
    {
        $ids = collect($projetoIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $alunos = Aluno::query()
            ->whereIn('projeto_id', $ids)
            ->get(['id', 'projeto_id', 'nome'])
            ->sortBy(fn (Aluno $a) => mb_strtolower((string) $a->nome))
            ->groupBy('projeto_id');

        $arquivos = ProjetoDocumento::query()
            ->whereIn('projeto_id', $ids)
            ->where('tipo', TipoDocumento::TermoUsoImagem->value)
            ->when($corte !== null, fn ($q) => $q->where('created_at', '<=', $corte))
            ->with('pessoas')
            ->orderBy('id')
            ->get()
            ->groupBy('projeto_id');

        return $ids->mapWithKeys(fn (int $id) => [
            $id => $this->montar($alunos->get($id, collect()), $arquivos->get($id, collect())),
        ])->all();
    }

    /**
     * A cobertura **no evento** (Sprint 176): o envio online somado ao que o
     * balcão do credenciamento registrou. É a resposta do Mapa do Evento —
     * para a organização, o estudante que mostrou a autorização em papel está
     * tão coberto quanto o que a mandou pelo portal.
     *
     * A marcação do balcão (num credenciamento **concluído** até o corte) vale
     * mais que o envio online, como na ficha: "presente" cobre, "não
     * necessário" dispensa, e "ausente" deixa o estudante pendente mesmo com
     * arquivo — foi o atendente que viu o papel e não o aceitou. Cada
     * estudante leva a `origem` do que o cobre: `online`, `balcao` ou
     * `dispensado`.
     *
     * @param  iterable<int>  $projetoIds
     * @return array<int, array<string, mixed>>
     */
    public function noEvento(iterable $projetoIds, ?CarbonInterface $corte = null): array
    {
        $cobertura = $this->cobertura($projetoIds, $corte);
        $balcao = $this->marcacoesDoBalcao(array_keys($cobertura), $corte);

        foreach ($cobertura as $projetoId => $projeto) {
            $alunos = array_map(function (array $aluno) use ($balcao, $projetoId) {
                $marcada = $balcao[$projetoId][$aluno['id']] ?? null;

                [$coberto, $origem] = match ($marcada) {
                    SituacaoDocumento::Presente->value => [true, $aluno['coberto'] ? 'online' : 'balcao'],
                    SituacaoDocumento::NaoNecessario->value => [true, 'dispensado'],
                    SituacaoDocumento::Ausente->value => [false, null],
                    default => [$aluno['coberto'], $aluno['coberto'] ? 'online' : null],
                };

                return ['coberto' => $coberto, 'origem' => $origem] + $aluno;
            }, $projeto['alunos']);

            $linhas = collect($alunos);
            $cobertos = $linhas->where('coberto', true)->count();
            $situacao = SituacaoTermoImagem::de($cobertos, $linhas->count());

            $cobertura[$projetoId] = [
                'situacao' => $situacao->value,
                'situacao_label' => $situacao->label(),
                'cobertos' => $cobertos,
                'online' => $linhas->where('origem', 'online')->count(),
                'balcao' => $linhas->whereIn('origem', ['balcao', 'dispensado'])->count(),
                'alunos' => $alunos,
                'faltando' => $linhas->where('coberto', false)->pluck('nome')->values()->all(),
            ] + $projeto;
        }

        return $cobertura;
    }

    /**
     * O que o balcão marcou no item do termo de imagem, por projeto e
     * estudante — só de credenciamento concluído até o corte (rascunho é
     * conferência em andamento, não fato).
     *
     * @param  list<int>  $projetoIds
     * @return array<int, array<int, string>> projeto_id => [aluno_id => situação]
     */
    private function marcacoesDoBalcao(array $projetoIds, ?CarbonInterface $corte): array
    {
        $item = ItemConferencia::where('chave', ItemConferencia::CHAVE_TERMO_IMAGEM)->value('id');

        if ($item === null || $projetoIds === []) {
            return [];
        }

        $porProjeto = [];

        DB::table('credenciamento_documentos as cd')
            ->join('credenciamentos as c', 'c.id', '=', 'cd.credenciamento_id')
            ->whereIn('c.projeto_id', $projetoIds)
            ->whereNotNull('c.finalizado_em')
            ->when($corte !== null, fn ($q) => $q->where('c.finalizado_em', '<=', $corte))
            ->where('cd.item_id', $item)
            ->where('cd.pessoa_tipo', TipoPessoaCredenciamento::Aluno->value)
            ->get(['c.projeto_id', 'cd.pessoa_id', 'cd.situacao'])
            ->each(function ($m) use (&$porProjeto) {
                $porProjeto[(int) $m->projeto_id][(int) $m->pessoa_id] = (string) $m->situacao;
            });

        return $porProjeto;
    }

    /** A cobertura de um projeto só. */
    public function doProjeto(int $projetoId): array
    {
        return $this->cobertura([$projetoId])[$projetoId];
    }

    /**
     * @param  Collection<int, Aluno>  $alunos
     * @param  Collection<int, ProjetoDocumento>  $arquivos
     * @return array<string, mixed>
     */
    private function montar(Collection $alunos, Collection $arquivos): array
    {
        // Estudante => arquivos que o cobrem. O vínculo é por pessoa (Sprint
        // 183); o termo de imagem é dos estudantes, então só eles contam.
        $porAluno = [];
        $nomes = $alunos->pluck('nome', 'id');
        foreach ($arquivos as $arquivo) {
            foreach ($arquivo->pessoas->where('pessoa_tipo', 'aluno') as $pessoa) {
                $porAluno[$pessoa->pessoa_id][] = $arquivo->id;
            }
        }

        $linhas = $alunos->map(fn (Aluno $a) => [
            'id' => $a->id,
            'nome' => $a->nome,
            'coberto' => isset($porAluno[$a->id]),
            'arquivos' => $porAluno[$a->id] ?? [],
        ])->values();

        $cobertos = $linhas->where('coberto', true)->count();
        $situacao = SituacaoTermoImagem::de($cobertos, $linhas->count());

        return [
            'situacao' => $situacao->value,
            'situacao_label' => $situacao->label(),
            'cobertos' => $cobertos,
            'total' => $linhas->count(),
            'alunos' => $linhas->all(),
            'faltando' => $linhas->where('coberto', false)->pluck('nome')->values()->all(),
            'arquivos' => $arquivos->map(fn (ProjetoDocumento $d) => [
                'id' => $d->id,
                'nome_original' => $d->nome_original,
                'mime' => $d->mime,
                'tamanho_bytes' => $d->tamanho_bytes,
                'enviado_em' => $d->created_at?->toIso8601String(),
                'alunos' => $d->pessoas
                    ->where('pessoa_tipo', 'aluno')
                    ->filter(fn ($p) => $nomes->has($p->pessoa_id))
                    ->map(fn ($p) => ['id' => $p->pessoa_id, 'nome' => $nomes[$p->pessoa_id]])
                    ->sortBy(fn (array $a) => mb_strtolower((string) $a['nome']))
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }
}
