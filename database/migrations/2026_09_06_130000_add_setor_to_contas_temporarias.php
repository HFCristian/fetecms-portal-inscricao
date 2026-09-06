<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 105 — contas temporárias do almoxarifado.
 *
 * O balcão de guarda é atendido pela mesma gente de fora da organização que
 * atende o credenciamento, e pelo mesmo tipo de prazo curto. O que muda é a
 * **aba** que a conta abre — e é só isso que esta coluna diz.
 *
 * `credenciamento` é o padrão: é o que todas as contas existentes são, e o
 * comportamento não muda para nenhuma delas. Cada aba lista e administra
 * **só as suas** contas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_temporarias', function (Blueprint $table) {
            $table->string('setor', 20)->default('credenciamento')->after('curso');
        });
    }

    public function down(): void
    {
        Schema::table('contas_temporarias', function (Blueprint $table) {
            $table->dropColumn('setor');
        });
    }
};
