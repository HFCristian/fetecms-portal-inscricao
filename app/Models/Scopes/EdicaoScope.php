<?php

namespace App\Models\Scopes;

use App\Models\Edicao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Escopo de edição: toda consulta a projetos enxerga só a edição que está em
 * escopo agora (a que o usuário escolheu, ou a padrão).
 *
 * É por aqui que "trocar de edição troca todo o escopo" acontece de uma vez:
 * o `whereHas('projeto', …)` de alunos, coorientadores, anexos e avaliações
 * também passa por este filtro, então o recorte segue o projeto sem que cada
 * consulta precise saber da edição.
 *
 * Duas válvulas de segurança:
 *
 * - **sem nenhuma edição cadastrada, o escopo não filtra nada** (banco novo,
 *   testes que não criam edição);
 * - **projeto sem edição (`edicao_id` nulo) aparece em todas**. Não deveria
 *   existir depois do backfill da Sprint 66, mas se aparecer é melhor ficar
 *   visível para alguém corrigir do que sumir de todas as telas.
 *
 * Para atravessar o escopo de propósito (ex.: consultar o histórico de outra
 * edição), use `Projeto::withoutGlobalScope(EdicaoScope::class)`.
 */
class EdicaoScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return;
        }

        $coluna = $model->qualifyColumn('edicao_id');

        $builder->where(fn (Builder $q) => $q
            ->where($coluna, $edicao->id)
            ->orWhereNull($coluna));
    }
}
