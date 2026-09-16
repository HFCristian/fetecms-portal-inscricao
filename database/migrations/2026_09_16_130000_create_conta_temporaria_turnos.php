<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 133 — **voluntários** da avaliação presencial: contas temporárias com
 * **vários turnos de trabalho** definidos de uma vez.
 *
 * A conta temporária nasceu com uma janela só (Sprint 82/87), que é o formato
 * do balcão: um turno, uma conta. O voluntário do evento trabalha de outro
 * jeito — sábado de manhã e domingo à tarde, por exemplo —, e cadastrar duas
 * contas para a mesma pessoa multiplicaria a senha e o CPF no banco.
 *
 * Os turnos **não substituem** a janela: `valido_de`/`expira_em` continuam
 * sendo o **envelope** (o primeiro início e o último fim), de modo que a
 * varredura de vencidas e a lista continuam funcionando sem saber de turno
 * nenhum. O que os turnos acrescentam é uma trava fina no login: entre um turno
 * e outro a conta existe, está dentro do envelope e mesmo assim não abre — que
 * é exatamente o que "turno de trabalho" quer dizer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conta_temporaria_turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_temporaria_id')
                ->constrained('contas_temporarias')
                ->cascadeOnDelete();
            $table->timestamp('inicio');
            $table->timestamp('fim');
            $table->timestamps();

            $table->index(['conta_temporaria_id', 'inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conta_temporaria_turnos');
    }
};
