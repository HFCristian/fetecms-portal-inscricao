<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 193 — recomendações do comitê especial: o avaliador da comissão
 * recomenda um projeto finalista para uma credencial ou prêmio; a
 * organização aprova ou rejeita. Sem teto por avaliador nem por credencial —
 * o único limite é não repetir a mesma recomendação (mesma pessoa, mesmo
 * projeto, mesma credencial).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recomendacoes_premiacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credencial_id')->constrained('credenciais')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->foreignId('avaliador_id')->constrained('users')->cascadeOnDelete();
            $table->text('justificativa')->nullable();
            $table->string('status', 20)->default('pendente');
            $table->foreignId('decidida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidida_em')->nullable();
            $table->timestamps();

            $table->unique(['credencial_id', 'projeto_id', 'avaliador_id']);
            $table->index(['credencial_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recomendacoes_premiacao');
    }
};
