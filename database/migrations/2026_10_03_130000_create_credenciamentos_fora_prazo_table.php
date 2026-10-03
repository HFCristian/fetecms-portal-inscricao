<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciamento **fora do prazo** aprovado (Sprint 161).
 *
 * Algumas equipes pediram para credenciar depois — não estarão no domingo — e a
 * organização aprovou. Sem a marcação no sistema, o balcão e os avaliadores
 * enxergam só um estande vazio e um projeto "não credenciado", e a explicação
 * mora numa lista à parte que nem todo mundo tem. Uma linha por projeto: a data
 * prevista de chegada (opcional) e a observação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciamentos_fora_prazo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->unique()->constrained('projetos')->cascadeOnDelete();
            $table->timestamp('previsto_em')->nullable();
            $table->string('observacao', 500)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciamentos_fora_prazo');
    }
};
