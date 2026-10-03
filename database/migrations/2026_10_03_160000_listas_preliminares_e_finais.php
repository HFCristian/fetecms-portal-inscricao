<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Listas **preliminares** e **finais** (Sprint 164).
 *
 * Até aqui toda lista era "lista final": nascia rascunho e, publicada, virava a
 * vigente. A organização trabalha com mais de um recorte antes de fechar a
 * feira — por categoria, por comissão, por rodada de recurso —, e esses recortes
 * não são finalistas de ninguém. Agora `tipo` separa os dois:
 *
 * - **preliminar**: várias convivem, geradas ou em rascunho; nenhuma define
 *   finalista.
 * - **final**: só **uma ativa** por edição (`vigente`), e é ela que vale para a
 *   etapa presencial inteira. Pode nascer da classificação ou da **união de
 *   preliminares** escolhidas — `origens` guarda quais.
 *
 * As listas que já existem: o que foi publicado vira **final** (a vigente
 * continua ativa, com códigos, mapa e crachás intactos) e o que ainda era
 * rascunho vira **preliminar** — era isso que ele era na prática.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listas_finais', function (Blueprint $table) {
            $table->string('tipo', 20)->default('final')->after('nome');
            $table->json('origens')->nullable()->after('cotas');
            $table->timestamp('gerada_em')->nullable()->after('origens');
        });

        DB::table('listas_finais')->where('rascunho', true)->where('demo', false)->update(['tipo' => 'preliminar']);
        DB::table('listas_finais')->where('rascunho', false)->update(['gerada_em' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('listas_finais', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'origens', 'gerada_em']);
        });
    }
};
