<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 85 — o **piso da fila do avaliador**.
 *
 * As regras por categoria (Avaliação Online → Algoritmo de distribuição) dizem
 * quais projetos entram na distribuição automática. O efeito colateral é que,
 * numa configuração restritiva ("só FUNDECT com 0 avaliações"), sobra pouco
 * projeto elegível e o avaliador fica com a fila quase vazia — sem trabalho.
 *
 * O piso resolve isso sem afrouxar a regra: a distribuição continua obedecendo
 * às regras por categoria e, **só quando o avaliador fica abaixo deste número**,
 * uma segunda passada completa a fila dele ignorando as regras (a "regra
 * geral"). Ou seja, a regra manda em quem entra primeiro; o piso garante que
 * ninguém fique parado.
 *
 * Em branco, o piso não existe e o comportamento é o de antes desta sprint.
 * O padrão nasce em 6, o número pedido pela organização.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('piso_fila_avaliador')->nullable()->default(6)->after('avaliacoes_max_por_avaliador');
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('piso_fila_avaliador');
        });
    }
};
