<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 222 — as **vagas** da avaliação presencial.
 *
 * - `edicoes.presencial_por_projeto` (o teto único) dá lugar a dois números e
 *   um peso: avaliações **comuns** por projeto (padrão 2), da **comissão
 *   especial** (padrão 1) e o **peso** da avaliação da comissão na média
 *   (padrão 2). Em branco valem os padrões de `JanelaTurnos`; zero é resposta.
 * - `avaliacoes_presenciais.vaga`: a vaga que a avaliação ocupa, `comum` ou
 *   `comite`. Fica gravada para o peso não mudar se o avaliador sair da
 *   comissão depois. As que já existem viram `comite` quando o avaliador é da
 *   comissão e o projeto ainda não tinha uma — a primeira, por ordem de
 *   chegada; o resto fica `comum`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('presencial_comuns_por_projeto')->nullable()->after('presencial_fila_avaliador');
            $table->unsignedSmallInteger('presencial_comite_por_projeto')->nullable()->after('presencial_comuns_por_projeto');
            $table->decimal('presencial_peso_comite', 4, 2)->nullable()->after('presencial_comite_por_projeto');
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('presencial_por_projeto');
        });

        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->string('vaga', 10)->default('comum')->after('escolha_livre');
        });

        $comissao = DB::table('avaliador_profiles')->where('comissao_especial', true)->pluck('user_id')->all();

        if ($comissao === []) {
            return;
        }

        $primeiras = DB::table('avaliacoes_presenciais')
            ->whereIn('avaliador_id', $comissao)
            ->orderBy('id')
            ->get(['id', 'projeto_id'])
            ->unique('projeto_id')
            ->pluck('id')
            ->all();

        if ($primeiras !== []) {
            DB::table('avaliacoes_presenciais')->whereIn('id', $primeiras)->update(['vaga' => 'comite']);
        }
    }

    public function down(): void
    {
        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->dropColumn('vaga');
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('presencial_por_projeto')->nullable()->after('presencial_fila_avaliador');
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn(['presencial_comuns_por_projeto', 'presencial_comite_por_projeto', 'presencial_peso_comite']);
        });
    }
};
