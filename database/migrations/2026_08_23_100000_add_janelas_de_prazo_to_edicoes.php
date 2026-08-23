<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha as duas janelas da edição: a de inscrição (abertura + prazo, este já
 * existia) e a de avaliação (liberação, que já existia, + encerramento).
 *
 * Sem data definida, cada ponta fica aberta — quem nunca configurar nada
 * continua com o comportamento de antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dateTime('submissoes_de')->nullable()->after('inscricoes_abertas');
            $table->dateTime('avaliacao_encerrada_em')->nullable()->after('avaliacao_liberada_em');
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn(['submissoes_de', 'avaliacao_encerrada_em']);
        });
    }
};
