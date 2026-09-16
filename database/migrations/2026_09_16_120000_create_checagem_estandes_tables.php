<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 132 — **checagem presencial de estandes**, a primeira seção da aba
 * Avaliação presencial.
 *
 * No dia da feira alguém percorre os estandes e confere se o trabalho está de
 * pé: o banner montado, a equipe no lugar, o material que o projeto prometeu.
 * Hoje isso é prancheta e papel, e o que foi conferido some com a prancheta.
 *
 * - `itens_checagem_estande`: o que se confere em cada estande. É **catálogo**,
 *   como a lista de documentos do credenciamento — não é escopado por edição,
 *   e item já usado numa checagem é **desativado**, nunca excluído.
 * - `checagens_estande`: uma por projeto finalista — quem conferiu e quando.
 *   `demo` separa o ensaio do que vale, como no credenciamento e no
 *   almoxarifado.
 * - `checagem_estande_itens`: item a item, com o nome **desnormalizado** para o
 *   registro sobreviver a uma renomeação do catálogo depois do evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itens_checagem_estande', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->string('descricao')->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('checagens_estande', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->foreignId('lista_final_id')->nullable()->constrained('listas_finais')->nullOnDelete();
            $table->foreignId('verificado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verificado_em')->nullable();
            $table->text('observacao')->nullable();
            $table->boolean('demo')->default(false);
            $table->timestamps();

            // Uma checagem por projeto em cada trilha (a de verdade e a de ensaio).
            $table->unique(['projeto_id', 'demo']);
        });

        Schema::create('checagem_estande_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checagem_id')->constrained('checagens_estande')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('itens_checagem_estande')->nullOnDelete();
            $table->string('item_nome');
            $table->string('situacao');   // presente | ausente | nao_necessario
            $table->timestamps();

            $table->unique(['checagem_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checagem_estande_itens');
        Schema::dropIfExists('checagens_estande');
        Schema::dropIfExists('itens_checagem_estande');
    }
};
