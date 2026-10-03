<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requisições de **suporte** de um projeto finalista (Sprint 162):
 * acompanhante (estudante neurodivergente ou com deficiência), intérprete de
 * Libras ou intérprete de outra língua.
 *
 * Uma linha por pedido. O acompanhante é uma **pessoa** que entra no evento —
 * por isso o nome, o documento e o vínculo com o estudante ficam na própria
 * linha, e o id dela vira o código do crachá (papel `S`). O orientador pede, a
 * organização aprova ou recusa: só o aprovado aparece para as equipes de
 * credenciamento e de avaliação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suportes_projeto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->string('tipo', 30);
            // Só no intérprete de outra língua: qual.
            $table->string('idioma', 60)->nullable();
            // O estudante que precisa do suporte.
            $table->foreignId('aluno_id')->nullable()->constrained('alunos')->nullOnDelete();
            $table->string('acompanhante_nome')->nullable();
            $table->string('acompanhante_documento', 60)->nullable();
            $table->string('acompanhante_vinculo', 80)->nullable();
            $table->string('observacao', 1000)->nullable();
            $table->string('status', 20)->default('pendente');
            $table->string('motivo', 500)->nullable();
            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_em')->nullable();
            $table->timestamps();

            $table->index(['projeto_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suportes_projeto');
    }
};
