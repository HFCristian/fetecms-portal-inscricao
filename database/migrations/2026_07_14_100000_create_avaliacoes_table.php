<?php

use App\Enums\StatusAvaliacao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avaliação de um projeto submetido por um avaliador (E7). O algoritmo de
        // distribuição ainda será feito; a tabela já existe para as telas do admin.
        Schema::create('avaliacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->foreignId('avaliador_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default(StatusAvaliacao::Designada->value);
            $table->unsignedTinyInteger('nota')->nullable(); // 1 a 10, preenchida ao concluir
            $table->timestamps();

            // Um avaliador avalia cada projeto no máximo uma vez.
            $table->unique(['projeto_id', 'avaliador_id']);
            $table->index(['avaliador_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacoes');
    }
};
