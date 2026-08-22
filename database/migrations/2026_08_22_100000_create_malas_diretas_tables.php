<?php

use App\Enums\StatusDestinatario;
use App\Enums\StatusMala;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mala direta do admin: um comunicado disparado para um recorte da base.
     *
     * A lista de destinatários é gravada como SNAPSHOT no momento do disparo
     * (tabela `mala_direta_destinatarios`), e não recalculada depois: o
     * relatório precisa dizer para quem a mensagem foi de fato enviada, mesmo
     * que a pessoa troque de e-mail, submeta o projeto ou saia da base.
     */
    public function up(): void
    {
        Schema::create('malas_diretas', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->text('justificativa');
            // Quem pediu o disparo (secretaria, coordenação...). Metadado interno:
            // não aparece para o destinatário, só na auditoria do painel.
            $table->string('solicitante')->nullable();
            $table->string('assunto');
            $table->text('corpo');
            // Critério usado no disparo, para o admin saber de onde saiu a lista.
            $table->json('publicos');
            $table->unsignedInteger('emails_personalizados')->default(0);
            $table->string('status')->default(StatusMala::Enviando->value);

            // Autor desnormalizado: o relatório continua legível se a conta sumir.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('autor_nome')->nullable();
            $table->string('autor_email')->nullable();

            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            $table->index('enviado_em');
            $table->index(['status', 'created_at']);
        });

        Schema::create('mala_direta_destinatarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mala_direta_id')->constrained('malas_diretas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('nome')->nullable();
            $table->string('papel')->nullable();
            // De quais públicos (ou da lista personalizada) este e-mail veio.
            $table->json('origens');
            // Snapshot dos projetos do orientador — é o que o CSV exporta.
            $table->unsignedInteger('projetos_total')->default(0);
            $table->json('projetos_titulos')->nullable();

            $table->string('status')->default(StatusDestinatario::Pendente->value);
            $table->text('erro')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();

            // Um e-mail só recebe uma vez a mesma mala, venha de quantos públicos vier.
            $table->unique(['mala_direta_id', 'email']);
            $table->index(['mala_direta_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mala_direta_destinatarios');
        Schema::dropIfExists('malas_diretas');
    }
};
