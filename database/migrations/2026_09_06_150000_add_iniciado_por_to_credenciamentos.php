<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 109 — credenciamento salvo como rascunho.
 *
 * O atendimento nem sempre termina de uma vez: o aluno esqueceu o RG no ônibus
 * e sai para buscar, e o balcão precisa guardar o que já conferiu em vez de
 * recomeçar quando ele voltar. Um credenciamento com `finalizado_em` nulo passa
 * a ser exatamente isso — um rascunho.
 *
 * `iniciado_por` é o **dono** do rascunho, e é o que faz a regra de posse
 * funcionar: `credenciado_por` só é preenchido na conclusão, então sem esta
 * coluna não haveria como saber de quem é um atendimento em aberto.
 *
 * O backfill aponta para quem credenciou: as linhas que já existem estão todas
 * concluídas, e nelas as duas pessoas são a mesma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credenciamentos', function (Blueprint $table) {
            $table->foreignId('iniciado_por')->nullable()->after('credenciado_por')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('credenciamentos')->whereNull('iniciado_por')->update([
            'iniciado_por' => DB::raw('credenciado_por'),
        ]);
    }

    public function down(): void
    {
        Schema::table('credenciamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('iniciado_por');
        });
    }
};
