<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprints 259–260 — listas de certificados.
 *
 * A aba Certificados passou a montar a lista num assistente: fases, público,
 * carga horária e conferência dos nomes. O que o admin decide fica em
 * `certificado_listas.config` (JSON: mexer nas regras não pede migration), e a
 * correção de nome em `nomes` — vale só na lista, o cadastro da pessoa não muda.
 *
 * Gerada, a lista é **congelada** em `certificado_linhas`: o certificado é
 * emitido sobre aquele retrato, e uma avaliação concluída depois (ou um
 * estudante trocado no projeto) não pode mudar o arquivo que já foi para o
 * SICERT. Editar e gerar de novo substitui o retrato inteiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificado_listas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->nullable()->constrained('edicoes')->nullOnDelete();
            $table->string('nome', 120);
            $table->string('status', 20)->default('rascunho');   // rascunho | gerada
            $table->unsignedTinyInteger('passo')->default(0);
            $table->json('config')->nullable();
            $table->json('nomes')->nullable();
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('gerada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('gerada_em')->nullable();
            // Mexeram na lista depois de gerada: o retrato está desatualizado.
            $table->timestamp('alterada_em')->nullable();
            $table->unsignedInteger('total_pessoas')->default(0);
            $table->unsignedInteger('total_certificados')->default(0);
            $table->timestamps();

            $table->index(['edicao_id', 'updated_at']);
        });

        Schema::create('certificado_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_id')->constrained('certificado_listas')->cascadeOnDelete();
            $table->string('categoria', 40);
            $table->string('pessoa_chave', 40);
            $table->string('nome');
            $table->string('nome_original');
            $table->string('cpf', 11)->nullable();
            $table->string('email')->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('funcao', 80);
            $table->string('tipo_atividade', 80);
            $table->text('titulo')->nullable();
            $table->string('campo_generico')->nullable();
            $table->unsignedInteger('quantidade')->nullable();
            $table->unsignedInteger('minutos');
            $table->boolean('teto_aplicado')->default(false);
            $table->date('data_inicio')->nullable();
            $table->date('data_fim')->nullable();
            $table->foreignId('projeto_id')->nullable()->constrained('projetos')->nullOnDelete();
            $table->unsignedInteger('ordem');
            $table->timestamps();

            $table->index(['lista_id', 'categoria', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificado_linhas');
        Schema::dropIfExists('certificado_listas');
    }
};
