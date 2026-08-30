<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 70 — credenciamento dos finalistas no dia do evento.
 *
 * - `documentos_credenciamento`: a lista de documentos exigida de cada papel
 *   (aluno, orientador, coorientador), parametrizável pelo admin. É catálogo,
 *   como áreas e escolas, então não é escopado por edição.
 * - `credenciamentos`: um por projeto finalista — quem credenciou e quando.
 * - `credenciamento_documentos`: a conferência item a item, por pessoa. Nome e
 *   papel ficam desnormalizados para o registro sobreviver a uma mudança de
 *   equipe depois do evento.
 *
 * A janela do evento e os itens entregues aos finalistas ficam na edição:
 * cada ano tem as suas datas e a sua sacola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_credenciamento', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_pessoa');       // aluno | orientador | coorientador
            $table->string('nome');
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['tipo_pessoa', 'nome']);
        });

        Schema::create('credenciamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->unique()->constrained('projetos')->cascadeOnDelete();
            $table->foreignId('lista_final_id')->nullable()->constrained('listas_finais')->nullOnDelete();
            $table->foreignId('credenciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('finalizado_em')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();
        });

        Schema::create('credenciamento_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credenciamento_id')->constrained('credenciamentos')->cascadeOnDelete();
            $table->foreignId('documento_credenciamento_id')->constrained('documentos_credenciamento')->cascadeOnDelete();
            $table->string('pessoa_tipo');       // aluno | orientador | coorientador
            $table->unsignedBigInteger('pessoa_id')->nullable();
            $table->string('pessoa_nome');
            $table->string('situacao');          // presente | ausente | nao_necessario
            $table->timestamps();

            $table->unique(
                ['credenciamento_id', 'documento_credenciamento_id', 'pessoa_tipo', 'pessoa_id'],
                'credenciamento_documento_pessoa_unico',
            );
        });

        Schema::table('edicoes', function (Blueprint $table) {
            // Janela do evento: fora dela o credenciamento fica só de leitura.
            $table->timestamp('evento_de')->nullable()->after('ajustes_ate');
            $table->timestamp('evento_ate')->nullable()->after('evento_de');
            // Itens entregues ao finalista no balcão (camiseta, crachá, kit…).
            $table->json('itens_credenciamento')->nullable()->after('evento_ate');
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn(['evento_de', 'evento_ate', 'itens_credenciamento']);
        });

        Schema::dropIfExists('credenciamento_documentos');
        Schema::dropIfExists('credenciamentos');
        Schema::dropIfExists('documentos_credenciamento');
    }
};
