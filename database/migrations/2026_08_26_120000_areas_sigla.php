<?php

use App\Models\Area;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sigla de três letras da área do conhecimento (AGR, BIO, SAU, EXA, HUM, SOC,
 * ENG, LIN): é o que aparece no código de cada projeto na lista final da feira
 * (FET.AGR-001). O backfill deduz a sigla pelo nome das áreas do catálogo; área
 * criada depois nasce sem sigla até o admin definir na Parametrização.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('sigla', Area::TAMANHO_SIGLA)->nullable();
        });

        DB::table('areas')->select('id', 'nome')->orderBy('id')->chunk(200, function ($areas) {
            foreach ($areas as $area) {
                $sigla = Area::siglaPeloNome($area->nome);

                if ($sigla !== null) {
                    DB::table('areas')->where('id', $area->id)->update(['sigla' => $sigla]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('sigla');
        });
    }
};
