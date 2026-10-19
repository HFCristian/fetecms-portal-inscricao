<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 245 — quando o projeto passou pelo balcão **pela primeira vez**.
 *
 * O credenciamento é um por projeto e vale o evento inteiro, mas regravar a
 * ficha no dia 2 move `finalizado_em` para o dia 2: o mapa passava a dizer
 * que a equipe chegou hoje. A partir do segundo dia a organização quer separar
 * quem já tinha sido credenciado de quem está chegando agora, e para isso a
 * primeira conclusão precisa ficar guardada à parte. Cancelar o credenciamento
 * apaga a linha, e a próxima conclusão volta a ser a primeira.
 *
 * O que já existe nasce com a hora que tem: é a melhor informação disponível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credenciamentos', function (Blueprint $table) {
            $table->timestamp('primeiro_finalizado_em')->nullable()->after('finalizado_em');
        });

        DB::table('credenciamentos')
            ->whereNotNull('finalizado_em')
            ->update(['primeiro_finalizado_em' => DB::raw('finalizado_em')]);
    }

    public function down(): void
    {
        Schema::table('credenciamentos', function (Blueprint $table) {
            $table->dropColumn('primeiro_finalizado_em');
        });
    }
};
