<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 247 — Cerimonial → **Fotografias de estandes**.
 *
 * Uma fotografia por projeto finalista na edição: o estande recebe um projeto
 * de manhã e outro à tarde, e a foto é do trabalho exposto, então ela segue o
 * projeto quando ele é remanejado de estande ou de turno.
 *
 * A mesma linha guarda o **"em registro"**: quem confirmou que está diante do
 * estande e ainda não salvou a foto (`em_registro_por`/`em_registro_desde`).
 * É o que impede dois fotógrafos de disputarem o mesmo estande; parada há mais
 * de 15 minutos, a reserva deixa de valer sozinha.
 *
 * O arquivo fica no disco privado (`caminho`) e é servido por rota
 * autenticada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fotografias_estande', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->string('caminho')->nullable();
            $table->string('nome_original')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registrado_em')->nullable();
            $table->foreignId('em_registro_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('em_registro_desde')->nullable();
            $table->timestamps();

            $table->unique(['edicao_id', 'projeto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fotografias_estande');
    }
};
