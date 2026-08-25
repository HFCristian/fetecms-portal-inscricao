<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De onde o avaliador é. Serve ao ranking de avaliadores e aos relatórios da
 * organização. Opcional: quem já tem conta segue válido sem preencher, e o
 * campo pode ser completado depois em /avaliador/perfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->foreignId('estado_id')->nullable()->constrained('estados')->nullOnDelete();
            $table->foreignId('cidade_id')->nullable()->constrained('cidades')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cidade_id');
            $table->dropConstrainedForeignId('estado_id');
        });
    }
};
