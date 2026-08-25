<?php

use App\Enums\GrupoCorrelato;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Áreas correlatas: cada área do conhecimento aponta para um grupo de "áreas
 * irmãs", usado como fallback da distribuição automática quando a própria área
 * não tem avaliador disponível.
 *
 * O backfill deduz o grupo pelo nome das áreas já cadastradas; o que não casar
 * fica sem grupo (sem irmã) até o admin escolher na Parametrização.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('grupo_correlato', 40)->nullable();
        });

        DB::table('areas')->select('id', 'nome')->orderBy('id')->chunk(200, function ($areas) {
            foreach ($areas as $area) {
                $grupo = GrupoCorrelato::peloNome($area->nome);

                if ($grupo !== null) {
                    DB::table('areas')->where('id', $area->id)->update(['grupo_correlato' => $grupo->value]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('grupo_correlato');
        });
    }
};
