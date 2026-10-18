<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 223 — a **liberação** do avaliador presencial.
 *
 * Confirmar o presencial (ou ser cadastrado pela organização) não basta: a
 * organização **libera** cada avaliador antes de ele avaliar, e a liberação
 * vale para o evento inteiro da edição — sem a cabine por turno que saiu na
 * Sprint 221. Revogar é apagar a linha. Por edição, para quem foi liberado num
 * ano não chegar liberado no seguinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliador_liberacoes_presenciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('liberado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['edicao_id', 'user_id'], 'avaliador_liberacao_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliador_liberacoes_presenciais');
    }
};
