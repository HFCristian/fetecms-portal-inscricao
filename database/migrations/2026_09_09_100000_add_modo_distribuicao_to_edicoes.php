<?php

use App\Enums\ModoDistribuicao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 110 — como a fila do avaliador é montada.
 *
 * Até aqui só havia um jeito: o admin distribuía em massa e cada avaliador saía
 * com a fila cheia, esperando ele aparecer. Numa feira em que boa parte dos
 * cadastrados nunca entra, isso manda projeto para o limbo — três designados,
 * nenhuma avaliação — enquanto quem está trabalhando fica sem o que avaliar.
 *
 * O modo **por atividade** inverte a ordem: ninguém recebe nada de antemão, a
 * fila é montada no login e devolvida ao bolo no fim da sessão. O padrão
 * continua sendo **total**, o comportamento histórico, então nada muda para
 * quem não trocar o modo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->string('modo_distribuicao', 20)
                ->default(ModoDistribuicao::Total->value)
                ->after('distribuicao_ao_cadastrar');
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('modo_distribuicao');
        });
    }
};
