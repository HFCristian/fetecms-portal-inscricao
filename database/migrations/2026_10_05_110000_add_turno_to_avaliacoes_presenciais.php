<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 170 — a **distribuição presencial** por turno.
 *
 * Cada avaliação presencial passa a saber em que **ocorrência** (dia × turno)
 * ela foi entregue: é dela que sai o prazo — o fim daquele turno mais 30
 * minutos — e é por ela que a organização acompanha quem está com o quê.
 * Nula na designação feita antes desta sprint: vale então o turno do projeto,
 * em qualquer dia do evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->date('dia')->nullable()->after('avaliador_id');
            $table->string('turno', 1)->nullable()->after('dia');
            $table->index(['edicao_id', 'dia', 'turno']);
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->dropIndex(['edicao_id', 'dia', 'turno']);
            $table->dropColumn(['dia', 'turno']);
        });
    }
};
