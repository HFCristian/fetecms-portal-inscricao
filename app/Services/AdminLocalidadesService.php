<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Models\Projeto;
use Illuminate\Support\Collection;

class AdminLocalidadesService
{
    /**
     * Projetos agregados por localidade (estados, cidades e escolas).
     *
     * Inclui rascunhos E submetidos; cada grupo traz o total e a separação por
     * status. Estados e cidades trazem ainda as escolas aninhadas. Localidades
     * sem nenhum projeto não aparecem; cada lista vem ordenada por total (desc).
     *
     * @return array{estados: array<int, mixed>, cidades: array<int, mixed>, escolas: array<int, mixed>}
     */
    public function agregado(): array
    {
        $projetos = Projeto::query()
            ->with(['estado:id,nome,uf', 'cidade:id,nome', 'instituicao:id,nome'])
            ->get();

        return [
            'estados' => $this->porEstado($projetos),
            'cidades' => $this->porCidade($projetos),
            'escolas' => $this->porEscola($projetos),
        ];
    }

    /** @param  Collection<int, Projeto>  $projetos */
    private function porEstado(Collection $projetos): array
    {
        return $projetos
            ->groupBy(fn (Projeto $p) => $p->estado_id ?? 0)
            ->map(function (Collection $itens) {
                $estado = $itens->first()->estado;

                return $this->grupo($estado?->id, $estado?->nome ?? 'Estado não informado', $itens, [
                    'uf' => $estado?->uf,
                    'escolas' => $this->escolas($itens),
                ]);
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /** @param  Collection<int, Projeto>  $projetos */
    private function porCidade(Collection $projetos): array
    {
        return $projetos
            ->groupBy(fn (Projeto $p) => $p->cidade_id ?? 0)
            ->map(function (Collection $itens) {
                $cidade = $itens->first()->cidade;
                $estado = $itens->first()->estado;

                return $this->grupo($cidade?->id, $cidade?->nome ?? 'Cidade não informada', $itens, [
                    'uf' => $estado?->uf,
                    'escolas' => $this->escolas($itens),
                ]);
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /** @param  Collection<int, Projeto>  $projetos */
    private function porEscola(Collection $projetos): array
    {
        return $projetos
            ->groupBy(fn (Projeto $p) => $p->instituicao_id ?? 0)
            ->map(function (Collection $itens) {
                $inst = $itens->first()->instituicao;
                $cidade = $itens->first()->cidade;
                $estado = $itens->first()->estado;

                return $this->grupo($inst?->id, $inst?->nome ?? 'Escola não informada', $itens, [
                    'cidade' => $cidade?->nome,
                    'uf' => $estado?->uf,
                ]);
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /** Escolas (instituições) aninhadas dentro de um estado/cidade. */
    private function escolas(Collection $itens): array
    {
        return $itens
            ->groupBy(fn (Projeto $p) => $p->instituicao_id ?? 0)
            ->map(function (Collection $grupo) {
                $inst = $grupo->first()->instituicao;

                return $this->grupo($inst?->id, $inst?->nome ?? 'Escola não informada', $grupo);
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Monta um grupo com a contagem por status (total/submetidos/rascunho),
     * mesclando campos extras (uf, escolas aninhadas, etc.).
     *
     * @param  Collection<int, Projeto>  $itens
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function grupo(?int $id, string $nome, Collection $itens, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'nome' => $nome,
            'total' => $itens->count(),
            'submetidos' => $itens->filter(fn (Projeto $p) => $p->status === ProjetoStatus::Submetido)->count(),
            'rascunho' => $itens->filter(fn (Projeto $p) => $p->status === ProjetoStatus::Rascunho)->count(),
        ], $extra);
    }
}
