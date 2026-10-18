<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 221 — a avaliação presencial sem a **cabine**.
 *
 * - `avaliador_turnos_presenciais` sai: a ativação do avaliador num turno era a
 *   porta da aba e o público da distribuição. Agora quem confirmou o presencial
 *   avalia no turno em andamento, escolhendo o estande no mapa, e a
 *   distribuição alcança todos que confirmaram.
 * - `avaliacoes_presenciais.escolha_livre`: a avaliação que o próprio avaliador
 *   escolheu no mapa (ou na lista), sem designação. É o que impede o envio dela
 *   de repor uma fila que a organização nunca distribuiu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('avaliador_turnos_presenciais');

        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->boolean('escolha_livre')->default(false)->after('designacao_manual');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->dropColumn('escolha_livre');
        });

        Schema::create('avaliador_turnos_presenciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('dia');
            $table->string('turno', 1);
            $table->foreignId('ativado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['edicao_id', 'user_id', 'dia', 'turno'], 'avaliador_turno_unico');
            $table->index(['edicao_id', 'dia', 'turno']);
        });
    }
};
