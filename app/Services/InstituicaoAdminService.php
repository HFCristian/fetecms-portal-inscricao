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

    /** Busca instituições por nome (com cidade, tipo e total de usos). Limite de 50. */
    public function buscar(?string $termo): array
    {
        $usos = $this->contarUsos();

        return Instituicao::with('cidade:id,nome')
            ->when(
                $termo !== null && $termo !== '',
                fn ($q) => $q->whereRaw('LOWER(nome) LIKE ?', ['%'.mb_strtolower($termo).'%'])
            )
            ->orderBy('nome')
            ->limit(50)
            ->get(['id', 'nome', 'cidade_id', 'tipo'])
            ->map(fn (Instituicao $i) => [
                'id' => $i->id,
                'nome' => $i->nome,
                'cidade' => $i->cidade?->nome,
                'tipo' => $i->tipo,
                'usos' => $usos[$i->id] ?? 0,
            ])->all();
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
