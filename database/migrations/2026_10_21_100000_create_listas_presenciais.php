<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 251 — as listas do **ranking presencial**: preliminares e finais, como
 * as da avaliação online, mas numa tabela própria.
 *
 * A separação é de propósito: a final ativa da online (`listas_finais`) define
 * quem é finalista — credenciamento, mapa, crachás —, e uma lista de premiados
 * pela nota do estande não pode mexer nisso. A final ativa daqui é o resultado
 * oficial da premiação, que o Cerimonial lê (Sprint 252).
 *
 * `lista_presencial_projetos` é a seção **por nota**: os projetos escolhidos
 * pela classificação (ou incluídos à mão). As premiações (credenciais e
 * prêmios) chegam na Sprint 253, em tabelas próprias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listas_presenciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->string('tipo', 20)->default('final');
            $table->boolean('vigente')->default(false);
            $table->boolean('rascunho')->default(true);
            // A trilha do modo de teste (lista final demo), separada da oficial.
            $table->boolean('demo')->default(false);
            $table->unsignedInteger('versao')->default(1);
            $table->json('cotas')->nullable();
            $table->json('origens')->nullable();
            $table->timestamp('gerada_em')->nullable();
            $table->foreignId('gerada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['edicao_id', 'tipo', 'vigente', 'demo']);
        });

        Schema::create('lista_presencial_projetos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_presencial_id')->constrained('listas_presenciais')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->boolean('manual')->default(false);
            $table->timestamps();

            $table->unique(['lista_presencial_id', 'projeto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lista_presencial_projetos');
        Schema::dropIfExists('listas_presenciais');
    }
};
