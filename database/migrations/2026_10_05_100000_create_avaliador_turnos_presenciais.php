<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 169 — os **turnos da avaliação presencial**.
 *
 * - `edicoes.horarios_turnos`: o horário de cada turno (`{"A": {"inicio":
 *   "08:00", "fim": "12:00"}, "B": {...}}`), que vale **em todos os dias** do
 *   evento. É hora de parede de Campo Grande, como as outras datas do portal.
 * - `edicoes.presencial_fila_avaliador` e `presencial_por_projeto`: os dois
 *   números da distribuição presencial — quantos projetos cada avaliador
 *   recebe por turno e quantas avaliações cada projeto recebe. Em branco valem
 *   os padrões da classe `JanelaTurnos`.
 * - `avaliador_turnos_presenciais`: a **ativação** do avaliador num turno de um
 *   dia. Ele chega ao evento, passa na cabine da avaliação e a organização o
 *   ativa para aquele turno — ou pré-ativa os turnos seguintes que ele
 *   escolher. Sem ativação ele não avalia, e a distribuição não o alcança:
 *   quem disse "sim" em setembro não necessariamente veio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->json('horarios_turnos')->nullable()->after('info_avaliacao_presencial');
            $table->unsignedSmallInteger('presencial_fila_avaliador')->nullable()->after('horarios_turnos');
            $table->unsignedSmallInteger('presencial_por_projeto')->nullable()->after('presencial_fila_avaliador');
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

    public function down(): void
    {
        Schema::dropIfExists('avaliador_turnos_presenciais');

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn(['horarios_turnos', 'presencial_fila_avaliador', 'presencial_por_projeto']);
        });
    }
};
