<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dois poderes que só o admin exerce sobre um avaliador:
 *
 * - `comissao_especial`: marca o avaliador como parte da comissão especial —
 *   um grupo que o admin designa em bloco e alcança na comunicação;
 * - áreas extras: além da área que o próprio avaliador escolheu, o admin pode
 *   liberá-lo para receber projetos de outras áreas/subáreas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->boolean('comissao_especial')->default(false);
        });

        Schema::create('avaliador_areas_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('avaliador_profile_id')->constrained('avaliador_profiles')->cascadeOnDelete();
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            // Subárea é opcional: sem ela, o avaliador atende a área inteira.
            $table->foreignId('subarea_id')->nullable()->constrained('subareas')->nullOnDelete();
            $table->timestamps();

            $table->unique(['avaliador_profile_id', 'area_id', 'subarea_id'], 'avaliador_area_extra_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliador_areas_extras');

        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->dropColumn('comissao_especial');
        });
    }
};
