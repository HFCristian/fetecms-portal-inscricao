<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 140 — `avaliacoes.iniciada_em`: quando o avaliador **abriu** esta
 * avaliação pela primeira vez.
 *
 * A tabela já guardava três datas, e nenhuma responde "quanto tempo ele levou
 * para avaliar": `created_at` é a designação (o projeto pode ficar semanas
 * esperando), `atividade_em` é o último toque (no fim, cola no envio) e
 * `concluida_em` é o envio. Sem a ponta inicial não há duração, e é a duração
 * que denuncia a avaliação preenchida em dois minutos — o padrão que a
 * Identificação de padrões procura.
 *
 * Só a **primeira** abertura grava: retomar um rascunho continua a mesma
 * avaliação, e reiniciar o relógio a cada volta apagaria justamente o trabalho
 * de quem leu com calma em duas sessões.
 *
 * As avaliações já concluídas ficam **nulas**, e não com um palpite: a tela
 * prefere dizer "sem registro de início" a acusar alguém com um número
 * inventado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->timestamp('iniciada_em')->nullable()->after('rascunho_em');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn('iniciada_em');
        });
    }
};
