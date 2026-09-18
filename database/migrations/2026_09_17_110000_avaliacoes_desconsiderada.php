<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 141 — a nota **desconsiderada**.
 *
 * Verificada a disparidade, o admin às vezes conclui que uma das notas não
 * representa o trabalho: o avaliador leu outro projeto, respondeu a rubrica
 * inteira com o mesmo número, ou avaliou alguém com quem tem contenda. Até aqui
 * a única saída era apagar a avaliação — e apagar destrói a prova justamente no
 * caso em que alguém vai contestar.
 *
 * Então a avaliação **fica**, com tudo o que tem dentro, e ganha três colunas
 * que dizem que ela não conta mais para a classificação: quando, quem e **por
 * quê**. A justificativa é obrigatória porque desconsiderar uma nota muda quem
 * entra na lista final — é escape do edital, e escape do edital se explica.
 *
 * O avaliador não perde nada por isso: o certificado e o ranking de quem mais
 * avaliou continuam contando a avaliação. Quem descartou a nota foi a
 * organização, e o trabalho aconteceu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->timestamp('desconsiderada_em')->nullable()->after('concluida_em');
            $table->foreignId('desconsiderada_por')->nullable()->after('desconsiderada_em')
                ->constrained('users')->nullOnDelete();
            $table->text('desconsiderada_motivo')->nullable()->after('desconsiderada_por');
            // Toda conta de classificação passa a filtrar por "não
            // desconsiderada" junto do status: é o índice que a mantém barata.
            $table->index(['status', 'desconsiderada_em']);
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropIndex(['status', 'desconsiderada_em']);
            $table->dropConstrainedForeignId('desconsiderada_por');
            $table->dropColumn(['desconsiderada_em', 'desconsiderada_motivo']);
        });
    }
};
