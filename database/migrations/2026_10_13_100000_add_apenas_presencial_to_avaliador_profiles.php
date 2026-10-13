<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 191 — avaliador cadastrado pela organização **só para o evento
 * presencial** (Avaliação presencial → Cadastro de avaliadores). A marca o
 * tira da distribuição da avaliação online: ele chega à feira para avaliar
 * no estande, e a fila online de quem não vai abri-la é projeto parado.
 *
 * `cadastrado_por` guarda quem da organização criou a conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->boolean('apenas_presencial')->default(false)->after('presencial_em');
            $table->foreignId('cadastrado_por')->nullable()->after('apenas_presencial')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cadastrado_por');
            $table->dropColumn('apenas_presencial');
        });
    }
};
