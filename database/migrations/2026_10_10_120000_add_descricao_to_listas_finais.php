<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 180 — **apelido e descrição** das listas preliminares e finais.
 *
 * O apelido é o `nome` que a lista já tinha (a tela passou a chamá-lo assim);
 * a descrição é nova: com várias preliminares convivendo, "Lista preliminar ·
 * XVI FETECMS" repetido não diz o que há em cada uma — "cotas da FUNDECT com
 * 70% de interior" diz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listas_finais', function (Blueprint $table) {
            $table->text('descricao')->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('listas_finais', function (Blueprint $table) {
            $table->dropColumn('descricao');
        });
    }
};
