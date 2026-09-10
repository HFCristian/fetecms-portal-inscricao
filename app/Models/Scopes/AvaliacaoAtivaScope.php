<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Escopo da avaliação **viva**: toda consulta enxerga só as avaliações que
 * ainda estão de pé, e não as que foram **devolvidas ao bolo**.
 *
 * Uma avaliação devolvida (`devolvida_em` preenchido) é o fim de uma sessão que
 * acabou ou de um projeto que ficou aberto tempo demais: o projeto volta na
 * hora para a distribuição, mas a linha **não é apagada** — dentro dela ficam
 * as respostas que o avaliador já tinha dado, para ele retomar de onde parou se
 * o projeto ainda aceitar avaliação.
 *
 * O escopo é global de propósito, e não um `whereNull` repetido em cada
 * consulta. As contas de cobertura estão espalhadas por meia dúzia de serviços
 * (a fila, a distribuição, a trava do início, os rankings, os cards de resumo)
 * e esquecer uma delas faria o projeto continuar ocupado depois de devolvido —
 * exatamente o que a devolução existe para desfazer. Filtrando aqui, quem
 * esquecer acerta assim mesmo.
 *
 * Para enxergar as devolvidas de propósito — a lista de rascunhos que o
 * avaliador pode retomar, ou a retomada em si —, use
 * `Avaliacao::withoutGlobalScope(AvaliacaoAtivaScope::class)` ou o atalho
 * `Avaliacao::comDevolvidas()`.
 */
class AvaliacaoAtivaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn('devolvida_em'));
    }
}
