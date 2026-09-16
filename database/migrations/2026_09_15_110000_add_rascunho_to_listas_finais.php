<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 128 — a lista final passa a ser **vista antes de ser baixada**.
 *
 * Até aqui "Gerar lista final" devolvia o TXT na hora: o recorte por cotas era
 * o arquivo, e corrigir uma escolha do algoritmo significava oficializar a
 * lista primeiro (Sprint 69) para só então incluir ou retirar projetos. Quem
 * não queria publicar nada ficava sem revisão nenhuma.
 *
 * Agora toda geração nasce como **rascunho**: uma lista registrada que ainda
 * não é a vigente da edição. O admin revê a composição, inclui e retira com
 * justificativa (a mesma trilha da lista oficial), baixa o TXT quando quiser e
 * publica se for o caso. `rascunho` é o que separa as duas coisas — e como ele
 * nasce `false`, as listas já publicadas continuam exatamente como estão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listas_finais', function (Blueprint $table) {
            $table->boolean('rascunho')->default(false)->after('vigente');
        });
    }

    public function down(): void
    {
        Schema::table('listas_finais', function (Blueprint $table) {
            $table->dropColumn('rascunho');
        });
    }
};
