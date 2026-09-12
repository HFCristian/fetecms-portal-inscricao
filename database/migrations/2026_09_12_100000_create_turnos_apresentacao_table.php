<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 118 — Mapa do Evento → **Turnos de Apresentação**.
 *
 * A feira tem mais finalistas do que estandes, e o mesmo estande recebe um
 * projeto de manhã e outro à tarde. Dividir quem apresenta em cada turno era
 * trabalho de planilha; aqui vira uma lista gerada a partir da **lista final
 * vigente**, com as regras que a organização escolher.
 *
 * - `turnos_apresentacao`: o turno de cada projeto. Uma linha por projeto e por
 *   edição (chave única), porque **cada projeto apresenta uma vez só** — é a
 *   regra que faz a conta dos estandes fechar. `regra` guarda quem o pôs ali
 *   (qual das cinco regras, ou o equilíbrio 50/50), e `manual` marca o que o
 *   admin moveu depois, para a regeneração não fingir que a escolha dele foi do
 *   algoritmo.
 * - `edicoes.turnos_config`: as capacidades e as regras, **guardadas entre
 *   gerações**. Gerar de novo é comum (chegou uma justificativa nova), e
 *   redigitar as listas de vestibular a cada vez seria inviável.
 * - `edicoes.turnos_gerados_em` / `turnos_gerados_por`: quando e por quem saiu a
 *   lista em vigor — só a última vale, então a tela precisa dizer qual é ela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos_apresentacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->string('turno', 1);
            // Qual regra colocou o projeto aqui (vestibular, fora_ms, equilibrio…).
            $table->string('regra', 40)->nullable();
            // O rótulo da lista que o alcançou ("UFMS 2026"), para a tela
            // explicar a alocação sem reabrir a configuração.
            $table->string('origem', 120)->nullable();
            $table->boolean('manual')->default(false);
            $table->timestamps();

            $table->unique(['edicao_id', 'projeto_id']);
            $table->index(['edicao_id', 'turno']);
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->json('turnos_config')->nullable()->after('ordem_abas');
            $table->timestamp('turnos_gerados_em')->nullable()->after('turnos_config');
            $table->foreignId('turnos_gerados_por')->nullable()->after('turnos_gerados_em')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('turnos_gerados_por');
            $table->dropColumn(['turnos_config', 'turnos_gerados_em']);
        });

        Schema::dropIfExists('turnos_apresentacao');
    }
};
