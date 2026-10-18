<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 238 — o comitê especial lança as avaliações do estande **depois**.
 *
 * O avaliador da comissão vai ao estande com um caderno e lança à noite. Duas
 * mudanças, e as duas valem só para o comitê:
 *
 * - `edicoes.presencial_comite_noite_inicio` / `_fim` (hh:mm, em branco
 *   desliga): o **lançamento noturno**, um horário que vale em cada dia do
 *   evento (Parametrização → Avaliação presencial → Comitê especial). Pode
 *   passar da meia-noite. Nele o comitê inicia e envia avaliações, como no
 *   horário de avaliação de um turno.
 * - `avaliacoes_presenciais.aberta_pelo_comite`: a avaliação aberta por quem é
 *   da comissão. O rascunho dela **não vence** com o fim do turno — fica com o
 *   avaliador até o envio, que só acontece num horário de avaliação ou no
 *   lançamento noturno. Gravado ao abrir, como a vaga: tirar alguém da
 *   comissão depois não faz o rascunho dele vencer de repente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->string('presencial_comite_noite_inicio', 5)->nullable();
            $table->string('presencial_comite_noite_fim', 5)->nullable();
        });

        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->boolean('aberta_pelo_comite')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->dropColumn('aberta_pelo_comite');
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn(['presencial_comite_noite_inicio', 'presencial_comite_noite_fim']);
        });
    }
};
