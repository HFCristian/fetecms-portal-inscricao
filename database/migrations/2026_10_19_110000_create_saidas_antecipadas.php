<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapa do Evento → **saída antecipada**: a equipe chegou, passou pelo balcão e
 * foi embora antes do fim do turno.
 *
 * Até aqui o mapa só sabia acumular — credenciado, checado, avaliado — e um
 * estande vazio no meio da tarde continuava pintado como "em avaliação". Uma
 * linha por projeto e por **dia** do evento: no dia seguinte a equipe pode
 * voltar, e a marcação de ontem não vale para hoje. A hora da saída fica em
 * `saiu_em`, que é o que o seletor de dia do mapa lê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saidas_antecipadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->date('dia');
            $table->timestamp('saiu_em');
            $table->string('observacao', 500)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['projeto_id', 'dia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saidas_antecipadas');
    }
};
