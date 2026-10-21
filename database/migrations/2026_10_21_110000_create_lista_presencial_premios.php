<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 253 — a seção de **premiações** da lista presencial: as credenciais e
 * os prêmios de Avaliação presencial → Credenciais e Prêmios, **copiados** na
 * criação da lista.
 *
 * É cópia de propósito (decisão do Pedro): depois de montada, a lista se edita
 * sem mexer no cadastro original. Por isso o nome, o tipo e o órgão ficam
 * gravados aqui — renomear ou excluir a credencial depois não reescreve uma
 * lista já gerada. `credencial_id` só lembra de onde ela veio (e casa a mesma
 * credencial na união de listas e no Cerimonial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lista_presencial_premios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lista_presencial_id')->constrained('listas_presenciais')->cascadeOnDelete();
            $table->foreignId('credencial_id')->nullable()->constrained('credenciais')->nullOnDelete();
            $table->string('tipo', 20);
            $table->string('nome');
            $table->string('orgao')->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('lista_presencial_premio_projetos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('premio_id')->constrained('lista_presencial_premios')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->boolean('manual')->default(false);
            $table->timestamps();

            $table->unique(['premio_id', 'projeto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lista_presencial_premio_projetos');
        Schema::dropIfExists('lista_presencial_premios');
    }
};
