<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 119 — Mapa do Evento → **Estandes dos Projetos**.
 *
 * Definido o turno de cada projeto (Sprint 118), falta dizer **em que número**
 * ele fica. A organização não distribui a esmo: cada categoria ocupa um bloco do
 * ginásio, para o visitante encontrar a FETEC Jr inteira junta e o avaliador não
 * atravessar o pavilhão entre um trabalho e outro da mesma área.
 *
 * - `estandes_projetos`: o número de cada projeto. Uma linha por projeto
 *   (chave única com a edição) e **um projeto por número em cada turno** — a
 *   segunda chave única é o que garante que dois trabalhos não sejam mandados
 *   para o mesmo lugar físico no mesmo horário. O `turno` viaja junto, embora
 *   derive da tabela de turnos: é ele que fecha a unicidade do número, e a
 *   consulta do mapa (que lê estande por estande) não precisa de mais um join.
 * - `edicoes.estandes_config`: as faixas por categoria, guardadas **como o
 *   admin as escreveu** ("1-4, 7-9"), para voltarem iguais à tela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estandes_projetos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->string('turno', 1);
            $table->unsignedInteger('numero');
            // Qual regra deu este número (a categoria, ou o resto livre).
            $table->string('regra', 40)->nullable();
            $table->boolean('manual')->default(false);
            $table->timestamps();

            $table->unique(['edicao_id', 'projeto_id']);
            $table->unique(['edicao_id', 'turno', 'numero']);
            $table->index(['edicao_id', 'numero']);
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->json('estandes_config')->nullable()->after('turnos_gerados_por');
            $table->timestamp('estandes_gerados_em')->nullable()->after('estandes_config');
            $table->foreignId('estandes_gerados_por')->nullable()->after('estandes_gerados_em')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estandes_gerados_por');
            $table->dropColumn(['estandes_config', 'estandes_gerados_em']);
        });

        Schema::dropIfExists('estandes_projetos');
    }
};
