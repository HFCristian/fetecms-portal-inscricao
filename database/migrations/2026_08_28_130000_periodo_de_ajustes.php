<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Período de ajustes: a janela, depois da avaliação online, em que o
     * orientador entra para responder às sugestões dos avaliadores.
     *
     * `projeto_ajustes` guarda a decisão de cada sugestão. A sugestão NUNCA
     * some da tela — o orientador pode mudar de ideia até o fim do prazo —,
     * então a linha guarda também o valor anterior, para desfazer.
     */
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->timestamp('ajustes_de')->nullable();
            $table->timestamp('ajustes_ate')->nullable();
        });

        Schema::create('projeto_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->foreignId('avaliacao_id')->constrained('avaliacoes')->cascadeOnDelete();
            // area | subarea (a sugestão de reclassificação da rubrica).
            $table->string('tipo');
            $table->boolean('aceito')->default(false);
            // O que o projeto tinha quando a sugestão foi aceita — é o valor de volta.
            $table->unsignedBigInteger('de_id')->nullable();
            $table->unsignedBigInteger('para_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_em')->nullable();
            $table->timestamps();

            $table->unique(['avaliacao_id', 'tipo']);
            $table->index(['projeto_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projeto_ajustes');

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn(['ajustes_de', 'ajustes_ate']);
        });
    }
};
