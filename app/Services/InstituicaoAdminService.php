<?php

namespace App\Services;

use App\Models\Instituicao;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestão das instituições de ensino pelo admin (tela "Parametrização" → Escolas):
 * buscar, renomear, mesclar (reatribuindo todas as referências) e excluir. É a
 * contrapartida da criação livre de escolas — permite limpar duplicatas com segurança.
 */
class InstituicaoAdminService
{
    /** Tabelas que referenciam instituicao_id. */
    private const TABELAS = ['projetos', 'alunos', 'orientador_profiles'];

    public const POR_PAGINA = 50;

    /**
     * Busca instituições (com cidade, tipo e total de usos), paginada em 50/página.
     * Ordenação: 'nome' (A–Z) ou 'criacao' (mais recentes primeiro, via id).
     *
     * @return array{itens: array, meta: array{pagina_atual:int, ultima_pagina:int, total:int, por_pagina:int}}
     */
    public function buscar(?string $termo, string $ordenar = 'nome', int $pagina = 1): array
    {
        $usos = $this->contarUsos();

        $query = Instituicao::query()
            ->with('cidade:id,nome')
            ->when(
                $termo !== null && $termo !== '',
                fn ($q) => $q->buscaNome($termo)
            );

        if ($ordenar === 'criacao') {
            $query->orderByDesc('id'); // id é monotônico com a criação (evita created_at nulo do import)
        } else {
            $query->orderBy('nome');
        }

        $paginado = $query->paginate(
            perPage: self::POR_PAGINA,
            columns: ['id', 'nome', 'cidade_id', 'tipo'],
            page: max(1, $pagina),
        );

        $itens = collect($paginado->items())->map(fn (Instituicao $i) => [
            'id' => $i->id,
            'nome' => $i->nome,
            'cidade' => $i->cidade?->nome,
            'tipo' => $i->tipo,
            'usos' => $usos[$i->id] ?? 0,
        ])->all();

        return [
            'itens' => $itens,
            'meta' => [
                'pagina_atual' => $paginado->currentPage(),
                'ultima_pagina' => $paginado->lastPage(),
                'total' => $paginado->total(),
                'por_pagina' => $paginado->perPage(),
            ],
        ];
    }

    public function renomear(Instituicao $instituicao, string $nome): void
    {
        $instituicao->update(['nome' => $nome]);
    }

    /** Mescla a instituição origem na destino: reatribui referências e exclui a origem. */
    public function mesclar(Instituicao $origem, Instituicao $destino): void
    {
        if ($origem->id === $destino->id) {
            throw ValidationException::withMessages([
                'destino_id' => 'Selecione uma instituição de destino diferente da que será mesclada.',
            ]);
        }

        DB::transaction(function () use ($origem, $destino) {
            foreach (self::TABELAS as $tabela) {
                DB::table($tabela)->where('instituicao_id', $origem->id)->update(['instituicao_id' => $destino->id]);
            }
            $origem->delete();
        });
    }

    public function excluir(Instituicao $instituicao): void
    {
        if ($this->contar($instituicao->id) > 0) {
            throw ValidationException::withMessages([
                'instituicao' => 'Esta instituição está em uso. Mescle-a em outra antes de excluir.',
            ]);
        }
        $instituicao->delete();
    }

    /** Usos de um id específico somando todas as tabelas. */
    private function contar(int $id): int
    {
        return collect(self::TABELAS)->sum(fn ($t) => DB::table($t)->where('instituicao_id', $id)->count());
    }

    /** Mapa id => total de usos (somado entre as tabelas), em poucas queries. */
    private function contarUsos(): array
    {
        $acc = [];
        foreach (self::TABELAS as $tabela) {
            $linhas = DB::table($tabela)
                ->select('instituicao_id', DB::raw('count(*) as total'))
                ->whereNotNull('instituicao_id')
                ->groupBy('instituicao_id')
                ->get();
            foreach ($linhas as $linha) {
                $acc[$linha->instituicao_id] = ($acc[$linha->instituicao_id] ?? 0) + $linha->total;
            }
        }

        return $acc;
    }
}
