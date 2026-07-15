<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data/hora a partir da qual a avaliação online é liberada aos avaliadores.
        // Nulo = ainda não liberada. Global (definida na edição atual).
        Schema::table('edicoes', function (Blueprint $table) {
            $table->timestamp('avaliacao_liberada_em')->nullable()->after('fim_em');
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('avaliacao_liberada_em');
        });
    }
};
