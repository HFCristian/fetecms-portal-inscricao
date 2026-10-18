<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 229 — a designação **online** feita em Comitê especial → Designações
 * passa a ser reconhecível.
 *
 * Até aqui ela nascia como qualquer designação manual do admin, e o
 * Acompanhamento das avaliações do comitê não tinha como separá-la do resto da
 * fila do avaliador. `designada_pelo_comite` marca a linha.
 *
 * O que já foi designado antes desta coluna não deixou rastro, então o passado
 * é marcado por aproximação: designação manual, para avaliador da comissão
 * especial, de projeto da lista final vigente, feita depois de a lista ser
 * gerada — o retrato do que a tela do comitê faz durante o evento. O que a
 * Avaliação online designou nesse recorte também cai aqui; é o preço de não
 * deixar o acompanhamento vazio para o que já aconteceu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->boolean('designada_pelo_comite')->default(false)->after('designacao_manual');
        });

        $ids = DB::table('avaliacoes as a')
            ->join('avaliador_profiles as p', 'p.user_id', '=', 'a.avaliador_id')
            ->join('lista_final_projetos as lp', 'lp.projeto_id', '=', 'a.projeto_id')
            ->join('listas_finais as l', 'l.id', '=', 'lp.lista_final_id')
            ->where('a.designacao_manual', true)
            ->where('a.pela_organizacao', false)
            ->where('p.comissao_especial', true)
            ->where('l.tipo', 'final')
            ->where('l.vigente', true)
            ->where('l.demo', false)
            ->whereRaw('a.created_at >= COALESCE(l.gerada_em, l.created_at)')
            ->distinct()
            ->pluck('a.id');

        foreach ($ids->chunk(500) as $lote) {
            DB::table('avaliacoes')->whereIn('id', $lote->all())->update(['designada_pelo_comite' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn('designada_pelo_comite');
        });
    }
};
