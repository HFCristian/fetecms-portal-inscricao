<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 144 — a avaliação da organização passa a **preceder** a
 * desconsideração da nota que ela substitui, e estas duas colunas são o que
 * liga uma à outra enquanto isso não se resolve.
 *
 * Até aqui a ordem era: desconsidera → cria a avaliação → o admin preenche. Ele
 * descartava a nota antes de ter lido o projeto, e desistir no meio deixava o
 * projeto com um parecer a menos e nada no lugar. Agora a rubrica abre primeiro
 * e a nota antiga **só sai da classificação no envio** — a substituição é um
 * ato só.
 *
 * Entre os dois momentos existe uma intenção guardada, e ela não cabe na tela:
 * o rascunho é retomável, inclusive noutra sessão, e a justificativa escrita lá
 * atrás precisa chegar inteira ao registro que a desconsideração vai gerar.
 * Por isso ela mora na própria linha da avaliação da organização:
 *
 * - `substitui_avaliacao_id` — a nota que sai quando esta for enviada. Fica
 *   depois disso como o registro de **o que ela veio substituir**;
 * - `substituicao_motivo` — a justificativa que o admin escreveu ao abrir, e
 *   que vai para Registros → Notas no momento em que a nota é desconsiderada.
 *
 * `nullOnDelete` porque a avaliação substituída é a parte frágil da relação: se
 * ela sumir, a avaliação da organização continua valendo pelo que é — uma nota
 * do projeto —, só deixa de apontar para algo que não existe mais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->foreignId('substitui_avaliacao_id')
                ->nullable()
                ->after('pela_organizacao')
                ->constrained('avaliacoes')
                ->nullOnDelete();
            $table->text('substituicao_motivo')->nullable()->after('substitui_avaliacao_id');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitui_avaliacao_id');
            $table->dropColumn('substituicao_motivo');
        });
    }
};
