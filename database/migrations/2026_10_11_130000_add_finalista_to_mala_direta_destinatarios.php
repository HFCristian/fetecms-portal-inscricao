<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 185 — o código do projeto e o turno de cada destinatário finalista,
 * congelados no disparo como o resto da lista: `[{codigo, titulo, turno}]`.
 * É deles que saem `{{codigo_projeto}}` e `{{turno}}`, e o relatório precisa
 * dizer o que foi enviado mesmo que a lista final mude depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mala_direta_destinatarios', function (Blueprint $table) {
            $table->json('finalista')->nullable()->after('projetos_titulos');
        });
    }

    public function down(): void
    {
        Schema::table('mala_direta_destinatarios', function (Blueprint $table) {
            $table->dropColumn('finalista');
        });
    }
};
