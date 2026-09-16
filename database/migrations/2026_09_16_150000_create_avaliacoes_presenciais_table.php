<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 135 — a **avaliação presencial**: a nota que o avaliador dá no
 * estande, no dia da feira.
 *
 * Tabela própria, e não uma coluna em `avaliacoes`, porque é outra avaliação:
 * outra rubrica (`App\Support\RubricaPresencial`), outro momento, outro efeito
 * — ela não mexe no ranking da avaliação online, que já está fechado quando o
 * evento começa, e sim na **premiação**. Misturar as duas na mesma tabela
 * obrigaria toda consulta de cobertura, fila e ranking a aprender a diferença.
 *
 * `designacao_manual` distingue o que o admin mandou avaliar do que o próprio
 * avaliador escolheu no local — os dois caminhos existem (o admin distribui
 * antes, e quem está no corredor pega o estande que está livre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacoes_presenciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->foreignId('avaliador_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('designada'); // designada | em_andamento | concluida
            $table->json('respostas')->nullable();
            $table->decimal('nota', 5, 2)->nullable();
            $table->text('comentario')->nullable();
            $table->boolean('designacao_manual')->default(false);
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();

            // Um avaliador avalia cada projeto uma vez.
            $table->unique(['projeto_id', 'avaliador_id']);
            $table->index(['edicao_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacoes_presenciais');
    }
};
