<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca as designações feitas à mão pelo admin. O avaliador pode sortear de novo
 * a própria fila, mas o que o admin designou (e o que já está em avaliação) não
 * entra no sorteio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->boolean('designacao_manual')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn('designacao_manual');
        });
    }
};
