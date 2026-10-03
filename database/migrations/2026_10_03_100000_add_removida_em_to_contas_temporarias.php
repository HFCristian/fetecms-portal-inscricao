<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remoção de conta temporária (Sprint 155).
 *
 * Conta que **nunca registrou nada** é apagada de verdade. A que já atendeu
 * alguém — credenciou, guardou material, fez check-in — não pode sumir: as
 * fichas apontam para ela (`credenciado_por`, `registrado_por`…) e é o nome
 * dela que responde "quem atendeu este projeto?". Essa é **arquivada**: o login
 * morre (e-mail trocado, senha embaralhada, conta inativa), ela sai da lista do
 * setor e o nome continua onde estava. `removida_em` é a marca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_temporarias', function (Blueprint $table) {
            $table->timestamp('removida_em')->nullable()->after('expira_em');
        });
    }

    public function down(): void
    {
        Schema::table('contas_temporarias', function (Blueprint $table) {
            $table->dropColumn('removida_em');
        });
    }
};
