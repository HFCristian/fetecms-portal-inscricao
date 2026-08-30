<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 84 — acompanhamento de "Distribuir" e "Redistribuir" avaliações.
 *
 * As duas ações eram uma requisição só que segurava a tela até terminar. Com a
 * base cheia isso é uma espera cega — e um bom candidato a estourar timeout.
 * Agora cada acionamento vira uma linha aqui, o trabalho vai para a fila e a
 * tela consulta esta tabela para desenhar a barra: `processados` de `total`.
 *
 * `relatorio` guarda o mesmo resumo que o endpoint devolvia antes (quantas
 * designações, quantos projetos ficaram sub-cobertos), então a tela mostra no
 * fim exatamente o que mostrava.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribuicoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            // 'distribuir' (completa o que falta) ou 'redistribuir' (devolve ao
            // bolo o que não foi aberto e sorteia de novo).
            $table->string('tipo', 20);
            $table->string('status', 20)->default('pendente');
            // Denominador e numerador da barra. `total` só é conhecido quando o
            // job começa e conta os projetos/avaliadores da rodada.
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processados')->default(0);
            $table->string('etapa')->nullable();
            $table->json('relatorio')->nullable();
            $table->text('erro')->nullable();
            $table->foreignId('iniciada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();

            $table->index(['edicao_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribuicoes');
    }
};
