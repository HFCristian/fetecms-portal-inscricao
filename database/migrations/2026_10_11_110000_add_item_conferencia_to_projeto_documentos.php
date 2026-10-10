<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 181 — o arquivo que o orientador envia antes do evento passa a dizer
 * **de qual item da lista** ele é (`item_conferencia_id`). Até aqui só havia os
 * dois termos, reconhecidos pelo tipo; agora qualquer item marcado "receber
 * antes" tem a sua seção na aba Documentos. Os termos já enviados são ligados
 * aos itens do sistema correspondentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projeto_documentos', function (Blueprint $table) {
            $table->foreignId('item_conferencia_id')->nullable()->after('tipo')
                ->constrained('itens_conferencia')->nullOnDelete();
        });

        foreach (['termo_responsabilidade', 'termo_uso_imagem'] as $chave) {
            $item = DB::table('itens_conferencia')->where('chave', $chave)->value('id');

            if ($item !== null) {
                DB::table('projeto_documentos')->where('tipo', $chave)->update(['item_conferencia_id' => $item]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('projeto_documentos', function (Blueprint $table) {
            $table->dropForeign(['item_conferencia_id']);
            $table->dropColumn('item_conferencia_id');
        });
    }
};
