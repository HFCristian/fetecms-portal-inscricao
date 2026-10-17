<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rubrica presencial de 2026 tem pesos de três casas, escolhidos para
     * dois projetos raramente empatarem, e cada ponto da escala vale 20% do
     * peso (0,2274 na pergunta 6.1). Duas casas desfariam esse desempate: a
     * nota passa a ser guardada com quatro, que é o que a soma produz.
     *
     * As notas já gravadas não mudam de valor.
     */
    public function up(): void
    {
        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->decimal('nota', 6, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->decimal('nota', 5, 2)->nullable()->change();
        });
    }
};
