<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 134 — **credenciais** da feira: as vagas de premiação que a FETECMS
 * distribui (indicações a feiras nacionais e internacionais, bolsas, prêmios
 * de instituições parceiras).
 *
 * A credencial é um **bem escasso com dono**: são N vagas por órgão, e no fim
 * do evento a organização precisa dizer, projeto a projeto, quem recebeu o
 * quê. Isso hoje vive numa planilha que ninguém audita.
 *
 * - `credenciais`: o que existe para dar — nome, órgão e quantas vagas.
 * - `credencial_projeto`: a quem foi dada, com quem atribuiu e quando. A
 *   unicidade impede dar a mesma credencial duas vezes ao mesmo projeto; o
 *   teto de vagas é conferido no serviço, que sabe contar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->string('nome');
            $table->string('orgao')->nullable();
            $table->text('descricao')->nullable();
            // Nulo = sem teto (o órgão decide depois quantas vagas dá).
            $table->unsignedSmallInteger('vagas')->nullable();
            $table->boolean('ativa')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->unique(['edicao_id', 'nome']);
        });

        Schema::create('credencial_projeto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credencial_id')->constrained('credenciais')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->foreignId('atribuida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('atribuida_em')->nullable();
            $table->string('observacao')->nullable();
            $table->timestamps();

            $table->unique(['credencial_id', 'projeto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credencial_projeto');
        Schema::dropIfExists('credenciais');
    }
};
