<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Limite individual de avaliações que o avaliador pode assumir (E7).
        // Nulo = sem limite (regra padrão). Definido pelo admin ao "limitar".
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('limite_avaliacoes')->nullable()->after('subarea_id');
        });
    }

    public function down(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->dropColumn('limite_avaliacoes');
        });
    }
};
