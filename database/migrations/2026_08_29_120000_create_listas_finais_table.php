<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 68 — a lista final deixa de ser só um arquivo baixado e vira registro.
 *
 * `listas_finais` guarda cada lista **oficial** gerada (com as cotas usadas, a
 * versão e quem gerou); `lista_final_projetos` é a composição dela — os
 * projetos que entraram, e por tabela os finalistas da feira, já que alunos,
 * orientador e coorientador saem do projeto.
 *
 * Uma lista por vez é a **vigente** dentro de cada edição: é ela que define
 * quem é finalista e, a partir da Sprint 70, quem aparece no credenciamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listas_finais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->string('nome');
            $table->boolean('vigente')->default(false);
            // Sobe a cada alteração da composição (Sprint 69): o arquivo baixado
            // sempre corresponde à versão corrente.
            $table->unsignedInteger('versao')->default(1);
            $table->json('cotas')->nullable();     // o recorte pedido na geração
            $table->foreignId('gerada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['edicao_id', 'vigente']);
        });

        Schema::create('lista_final_projetos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_final_id')->constrained('listas_finais')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            // Incluído à mão pelo admin depois da geração (Sprint 69)?
            $table->boolean('manual')->default(false);
            $table->timestamps();

            $table->unique(['lista_final_id', 'projeto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lista_final_projetos');
        Schema::dropIfExists('listas_finais');
    }
};
