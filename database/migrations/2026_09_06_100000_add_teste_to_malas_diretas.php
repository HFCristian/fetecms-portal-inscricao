<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 102 — envio de teste da mala direta.
 *
 * Antes de disparar para a base, o admin manda a mensagem pronta para os
 * endereços que ele escolher e confere a formatação na caixa de entrada. O
 * teste percorre **o mesmo caminho** do disparo de verdade (mala, snapshot de
 * destinatários e um job por endereço), porque é justamente o caminho que já
 * entregou e-mail sem formatação quando o worker da fila estava desatualizado —
 * um teste que não passasse pela fila não denunciaria isso.
 *
 * A coluna é o que separa um do outro: a mala de teste não aparece na lista de
 * disparos, mas o relatório dela abre normalmente pelo id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('malas_diretas', function (Blueprint $table) {
            $table->boolean('teste')->default(false)->after('formato');
        });
    }

    public function down(): void
    {
        Schema::table('malas_diretas', function (Blueprint $table) {
            $table->dropColumn('teste');
        });
    }
};
