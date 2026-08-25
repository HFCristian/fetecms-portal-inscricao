<?php

use App\Models\Edicao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mínimos da avaliação online, antes fixos no código (3 e 3): quantas avaliações
 * cada avaliador precisa concluir — que é também quantos projetos ele vê na tela
 * — e quantas cada projeto precisa receber. Agora são parâmetros da edição.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('avaliacoes_min_por_avaliador')->default(Edicao::PADRAO_MIN_POR_AVALIADOR);
            $table->unsignedSmallInteger('avaliacoes_min_por_projeto')->default(Edicao::PADRAO_MIN_POR_PROJETO);
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn(['avaliacoes_min_por_avaliador', 'avaliacoes_min_por_projeto']);
        });
    }
};
