<?php

namespace App\Services;

use App\Enums\SituacaoTermoImagem;
use App\Enums\TipoDocumento;
use App\Models\Aluno;
use App\Models\ProjetoDocumento;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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
            ->with('alunos:id,nome')
            ->orderBy('id')
            ->get()
            ->groupBy('projeto_id');

        return $ids->mapWithKeys(fn (int $id) => [
            $id => $this->montar($alunos->get($id, collect()), $arquivos->get($id, collect())),
        ])->all();
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
        // Estudante => arquivos que o cobrem.
        $porAluno = [];
        foreach ($arquivos as $arquivo) {
            foreach ($arquivo->alunos as $aluno) {
                $porAluno[$aluno->id][] = $arquivo->id;
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
                'alunos' => $d->alunos
                    ->sortBy(fn (Aluno $a) => mb_strtolower((string) $a->nome))
                    ->map(fn (Aluno $a) => ['id' => $a->id, 'nome' => $a->nome])
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }
}
