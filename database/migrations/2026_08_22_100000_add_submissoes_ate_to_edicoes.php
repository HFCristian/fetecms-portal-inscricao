<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data/hora limite para submeter inscrições. Nulo = sem prazo (inscrições
        // abertas). Depois dela a área do orientador fica só de leitura.
        Schema::table('edicoes', function (Blueprint $table) {
            $table->timestamp('submissoes_ate')->nullable()->after('avaliacao_liberada_em');
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('submissoes_ate');
        });
    }
};
