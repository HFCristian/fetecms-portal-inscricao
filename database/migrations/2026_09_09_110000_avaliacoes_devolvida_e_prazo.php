<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 112 — o fim da sessão do avaliador e o prazo da avaliação aberta.
 *
 * Duas colunas em `avaliacoes`:
 *
 * - `atividade_em`: quando o avaliador tocou nesta designação pela última vez
 *   (login, abrir, salvar rascunho). É por ela que a varredura sabe que a
 *   sessão de quem só fechou o navegador acabou — sem isso, quem nunca clica em
 *   "sair" deixaria os projetos presos para sempre.
 *
 * - `devolvida_em`: a avaliação foi devolvida ao bolo sem ser apagada. O
 *   projeto volta a ser distribuível na hora, mas **o que o avaliador já
 *   preencheu continua ali**: ele reabre, vê o rascunho e retoma, se o projeto
 *   ainda aceitar avaliação. Apagar seria mais simples e jogaria fora trabalho
 *   de gente que só demorou.
 *
 * E uma na edição: `dias_avaliacao_aberta`, o Y de "projeto em avaliação há
 * mais de Y dias volta para a pilha". Nulo desliga a regra, que é como o portal
 * se comportou até aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->timestamp('atividade_em')->nullable()->after('rascunho_em');
            $table->timestamp('devolvida_em')->nullable()->after('atividade_em');
            // Toda consulta de cobertura filtra por "não devolvida": é o índice
            // que a mantém barata quando a tabela cresce.
            $table->index(['devolvida_em', 'status']);
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('dias_avaliacao_aberta')->nullable()->after('modo_distribuicao');
            $table->unsignedSmallInteger('horas_sessao_avaliador')->nullable()->after('dias_avaliacao_aberta');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropIndex(['devolvida_em', 'status']);
            $table->dropColumn(['atividade_em', 'devolvida_em']);
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn(['dias_avaliacao_aberta', 'horas_sessao_avaliador']);
        });
    }
};
