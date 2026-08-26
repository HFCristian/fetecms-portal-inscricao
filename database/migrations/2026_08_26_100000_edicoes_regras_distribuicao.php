<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regras do algoritmo de distribuição (aba Avaliação Online): por categoria, se
 * ela entra na distribuição automática e em que faixa de avaliações concluídas
 * o projeto ainda pode receber avaliador. Nulo = o padrão de sempre (todas as
 * categorias, sem faixa), então nada muda para quem não configurar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->json('distribuicao_regras')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('distribuicao_regras');
        });
    }
};
