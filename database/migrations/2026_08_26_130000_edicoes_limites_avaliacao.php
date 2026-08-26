<?php

use App\Models\Avaliacao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os mínimos da avaliação ganharam par: agora cada parâmetro tem mínimo E
 * máximo, e o de projeto pode variar por categoria.
 *
 * - `avaliacoes_max_por_avaliador`: teto de avaliações que um mesmo avaliador
 *   pode acumular (designadas, em andamento e concluídas). Nasce NULO — sem
 *   teto —, que é como a fila se comportava até aqui: concluída uma avaliação,
 *   entra outra no lugar indefinidamente.
 * - `avaliacoes_max_por_projeto`: teto de avaliadores por projeto, antes a
 *   constante Avaliacao::TETO_POR_PROJETO.
 * - `avaliacoes_por_categoria`: JSON com o par min/max de cada categoria; o que
 *   ficar nulo segue os números gerais acima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('avaliacoes_max_por_avaliador')->nullable();
            $table->unsignedSmallInteger('avaliacoes_max_por_projeto')->default(Avaliacao::TETO_POR_PROJETO);
            $table->json('avaliacoes_por_categoria')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn([
                'avaliacoes_max_por_avaliador',
                'avaliacoes_max_por_projeto',
                'avaliacoes_por_categoria',
            ]);
        });
    }
};
