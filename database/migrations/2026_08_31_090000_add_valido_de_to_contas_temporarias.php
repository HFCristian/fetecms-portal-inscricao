<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 87 — contas temporárias agendadas.
 *
 * O prazo do balcão passou a ser contado em **horas** (o padrão nasceu em 5),
 * e com isso a conta virou algo que se prepara: o admin cadastra a equipe
 * inteira dias antes e diz **a partir de quando** cada acesso vale, em vez de
 * criar conta no meio do evento.
 *
 * `valido_de` é o começo dessa janela. **Nulo significa "vale desde já"** — é o
 * comportamento de antes, e é o que as contas existentes recebem no backfill,
 * então nada muda para quem já estava criado. Preenchido com data futura, a
 * conta existe, aparece na lista como *agendada* e o login é recusado até a
 * hora marcada (`ContaTemporariaService::impedimentoDeLogin`).
 *
 * A conta agendada continua com `is_active = true`: quem a bloqueia é a janela,
 * não o interruptor de conta desativada — misturar os dois faria a conta
 * agendada parecer encerrada na tela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_temporarias', function (Blueprint $table) {
            $table->timestamp('valido_de')->nullable()->after('curso');
            $table->index('valido_de');
        });
    }

    public function down(): void
    {
        Schema::table('contas_temporarias', function (Blueprint $table) {
            $table->dropIndex(['valido_de']);
            $table->dropColumn('valido_de');
        });
    }
};
