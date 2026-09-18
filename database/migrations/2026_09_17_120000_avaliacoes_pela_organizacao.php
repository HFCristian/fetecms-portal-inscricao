<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 142 — `avaliacoes.pela_organizacao`: a avaliação que o **próprio
 * admin** preencheu, no lugar de uma nota desconsiderada.
 *
 * Desconsiderar uma nota abre um buraco na cobertura do projeto, e nem sempre
 * dá para esperar outro avaliador: no fim do período, com a lista final para
 * fechar, quem repõe o parecer é a organização. Ela usa a **mesma rubrica**, e
 * a nota entra na média como qualquer outra — é o que a torna comparável.
 *
 * A coluna existe para separar as duas coisas que não podem se misturar:
 *
 * - a avaliação **conta para o projeto** (média, ranking, lista final);
 * - mas **não conta para a pessoa**: o admin não entra no ranking de quem mais
 *   avaliou nem acumula carga horária de certificado. Certificado é reconhecimento
 *   de trabalho voluntário de avaliação, e quem está no balcão da organização
 *   está fazendo outra coisa.
 *
 * Ela também desliga a reposição automática da fila ao concluir: o admin não
 * tem fila de avaliador para repor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->boolean('pela_organizacao')->default(false)->after('designacao_manual');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn('pela_organizacao');
        });
    }
};
