<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projetos', function (Blueprint $table) {
            // Programa PICTEC MS: só se aplica à categoria FETECMS. Quando marcado,
            // eleva o limite da equipe de 3 para 4 alunos (regra do edital).
            $table->boolean('pictec_ms')->default(false)->after('categoria');
        });
    }

    public function down(): void
    {
        Schema::table('projetos', function (Blueprint $table) {
            $table->dropColumn('pictec_ms');
        });
    }
};
