<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 192 — informações adicionais da credencial ou do prêmio: o que a
 * organização sabe e quem recomenda precisa ler (os critérios do órgão, a
 * faixa etária aceita, o que a vaga cobre). A `descricao` continua sendo a
 * linha curta do cartão; aqui cabe o texto longo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credenciais', function (Blueprint $table) {
            $table->text('informacoes')->nullable()->after('descricao');
        });
    }

    public function down(): void
    {
        Schema::table('credenciais', function (Blueprint $table) {
            $table->dropColumn('informacoes');
        });
    }
};
