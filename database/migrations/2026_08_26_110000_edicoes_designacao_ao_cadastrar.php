<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Toggle do algoritmo de distribuição: com ele ligado, o avaliador que acaba de
 * se cadastrar já sai da tela de cadastro com projetos na fila, em vez de
 * esperar a próxima distribuição do admin. Desligado por padrão — é o
 * comportamento de sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->boolean('distribuicao_ao_cadastrar')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('distribuicao_ao_cadastrar');
        });
    }
};
