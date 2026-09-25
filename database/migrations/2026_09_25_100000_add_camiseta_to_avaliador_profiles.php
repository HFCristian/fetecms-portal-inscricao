<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamanho de camiseta do avaliador.
 *
 * Orientador, aluno e coorientador já informavam o seu; o avaliador não, e ele
 * também recebe camiseta no dia do evento — a organização acabava descobrindo
 * o número por fora do portal, numa planilha à parte.
 *
 * É **opcional**, como nos demais papéis: quem não informa simplesmente não
 * entra na quebra por tamanho do painel. Nulável também porque quem já está
 * cadastrado não tem como ter respondido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->string('camiseta', 4)->nullable()->after('titulacao');
        });
    }

    public function down(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->dropColumn('camiseta');
        });
    }
};
