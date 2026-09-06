<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 106 — quantas designações a distribuição cria por projeto.
 *
 * Até aqui a distribuição em massa parava no **mínimo por projeto** (padrão 3):
 * o alvo dela era a cobertura que a feira precisa. Só que designar exatamente o
 * necessário deixa o resultado à mercê de quem não abre a avaliação — o projeto
 * fica com 3 designados e 1 avaliação feita.
 *
 * Agora o alvo é próprio, definido no **Algoritmo de distribuição**: dá para
 * designar mais gente do que o necessário e deixar que os primeiros a iniciar
 * fiquem com o projeto (a trava do início cuida do resto). Em branco, ele segue
 * o mínimo por projeto — que é exatamente o comportamento de sempre, então nada
 * muda para quem não configurar.
 *
 * O valor por categoria mora em `distribuicao_regras`, junto das outras regras
 * da mesma tela; esta coluna é o número geral, que vale para a categoria que
 * não tiver o seu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('designacoes_por_projeto')->nullable()->after('avaliacoes_max_por_projeto');
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('designacoes_por_projeto');
        });
    }
};
