<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 126 — verificação de **disparidade** entre as notas de um projeto.
 *
 * O ranking mostra a média; ele não mostra que a média de 7,00 pode ser um 9,50
 * com um 4,50 dentro. Quando dois avaliadores discordam assim, o número que
 * decide a lista final não representa nem um nem outro — e a saída é designar
 * mais alguém.
 *
 * `verificacoes_disparidade` guarda cada verificação feita (a diferença pedida,
 * quem pediu e quando) e `verificacao_disparidade_projetos`, a lista que ela
 * devolveu. Os dados do projeto vão **desnormalizados**: a verificação é o
 * retrato de um momento, e uma avaliação a mais depois dela muda a amplitude
 * sem que a lista consultada naquele dia deixe de ser o que foi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verificacoes_disparidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            // A diferença pedida pelo admin, em pontos da nota final (0 a 10).
            $table->decimal('diferenca', 5, 2);
            $table->unsignedInteger('total')->default(0);
            $table->foreignId('gerada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['edicao_id', 'created_at']);
        });

        Schema::create('verificacao_disparidade_projetos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verificacao_id')
                ->constrained('verificacoes_disparidade')
                ->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            // Congelados: o que a tela mostrou no dia da verificação.
            $table->string('titulo');
            $table->string('area')->nullable();
            $table->string('categoria')->nullable();
            $table->unsignedInteger('avaliacoes')->default(0);
            $table->decimal('nota_min', 5, 2)->nullable();
            $table->decimal('nota_max', 5, 2)->nullable();
            $table->decimal('amplitude', 5, 2)->nullable();
            $table->decimal('media', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['verificacao_id', 'projeto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verificacao_disparidade_projetos');
        Schema::dropIfExists('verificacoes_disparidade');
    }
};
