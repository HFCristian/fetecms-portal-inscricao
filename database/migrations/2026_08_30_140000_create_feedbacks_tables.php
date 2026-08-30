<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 86 — Comunicação → Feedback.
 *
 * O admin monta um questionário, escolhe os públicos (os mesmos recortes da
 * mala direta) e dispara. Quem é alcançado vê um balão ao entrar no portal e
 * pode responder ou dispensar; junto sai um e-mail de convite.
 *
 * **As respostas são anônimas**, e é por isso que são duas tabelas separadas:
 *
 * - `feedback_participacoes` sabe QUEM viu, dispensou ou respondeu — é o que
 *   evita pedir duas vezes e alimenta o "quantos responderam";
 * - `feedback_respostas` guarda O QUE foi respondido, **sem `user_id`**. As
 *   respostas de um mesmo preenchimento são agrupadas por `envio`, um
 *   identificador aleatório que não leva a ninguém.
 *
 * Cruzar as duas não devolve a autoria: a participação tem o horário, mas a
 * resposta não guarda de qual participação veio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->json('publicos');                   // list<PublicoMala::value>
            $table->string('status', 20)->default('enviando');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamps();

            $table->index(['edicao_id', 'status']);
        });

        Schema::create('feedback_perguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->constrained('feedbacks')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('tipo', 20);                 // TipoPerguntaFeedback
            $table->text('enunciado');
            $table->boolean('obrigatoria')->default(true);
            // Alternativas: as opções já COPIADAS do modelo (ou digitadas).
            $table->json('opcoes')->nullable();
            // Dissertativas: o limite e em que unidade ele é contado.
            $table->string('unidade', 20)->nullable();  // UnidadeLimiteResposta
            $table->unsignedInteger('minimo')->nullable();
            $table->unsignedInteger('maximo')->nullable();
            $table->timestamps();

            $table->index(['feedback_id', 'ordem']);
        });

        // Quem viu / dispensou / respondeu. Sem conteúdo de resposta.
        Schema::create('feedback_participacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->constrained('feedbacks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('visto_em')->nullable();
            $table->timestamp('dispensado_em')->nullable();
            $table->timestamp('respondido_em')->nullable();
            $table->timestamps();

            $table->unique(['feedback_id', 'user_id']);
        });

        // O conteúdo, sem dono. `envio` agrupa as respostas de um preenchimento.
        Schema::create('feedback_respostas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->constrained('feedbacks')->cascadeOnDelete();
            $table->foreignId('pergunta_id')->constrained('feedback_perguntas')->cascadeOnDelete();
            $table->uuid('envio');
            $table->text('valor');
            $table->timestamps();

            $table->index(['pergunta_id', 'envio']);
        });

        // Espelho do relatório da mala direta: para quem o convite foi mandado
        // e o que aconteceu com cada endereço.
        Schema::create('feedback_destinatarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_id')->constrained('feedbacks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Nome e e-mail desnormalizados: o relatório precisa dizer para quem
            // foi mesmo que a pessoa troque de endereço depois.
            $table->string('nome')->nullable();
            $table->string('email');
            $table->string('status', 20)->default('pendente');
            $table->text('erro')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();

            $table->index(['feedback_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_destinatarios');
        Schema::dropIfExists('feedback_respostas');
        Schema::dropIfExists('feedback_participacoes');
        Schema::dropIfExists('feedback_perguntas');
        Schema::dropIfExists('feedbacks');
    }
};
