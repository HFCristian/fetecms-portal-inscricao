<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 129 — o **termo de responsabilidade** do finalista, assinado no
 * gov.br, e o laudo da conferência da assinatura.
 *
 * `assinatura` guarda o laudo inteiro (quem assinou, o emissor, se o documento
 * foi alterado depois de assinado e o motivo em palavras);
 * `assinatura_valida` é o resumo booleano, que é o que a lista do balcão
 * precisa filtrar sem abrir o JSON de cada linha.
 *
 * Nulos nas duas colunas significam "não conferido" — é o estado de todo
 * documento anexado antes desta sprint e dos tipos que não pedem assinatura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projeto_documentos', function (Blueprint $table) {
            $table->boolean('assinatura_valida')->nullable()->after('tamanho_bytes');
            $table->json('assinatura')->nullable()->after('assinatura_valida');
        });
    }

    public function down(): void
    {
        Schema::table('projeto_documentos', function (Blueprint $table) {
            $table->dropColumn(['assinatura_valida', 'assinatura']);
        });
    }
};
