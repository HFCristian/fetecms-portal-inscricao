<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unifica área/subárea do orientador no MESMO catálogo usado por projeto e avaliador:
 * troca os campos-texto (area_conhecimento / subarea) por FK (area_id / subarea_id).
 * Também protege a criação global de subárea com índice único (area_id, nome).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subareas', function (Blueprint $table) {
            $table->unique(['area_id', 'nome']);
        });

        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('curso_formacao')->constrained('areas')->nullOnDelete();
            $table->foreignId('subarea_id')->nullable()->after('area_id')->constrained('subareas')->nullOnDelete();
        });

        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->dropColumn(['area_conhecimento', 'subarea']);
        });
    }

    public function down(): void
    {
        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->string('area_conhecimento')->nullable();
            $table->string('subarea')->nullable();
        });

        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('subarea_id');
        });

        Schema::table('subareas', function (Blueprint $table) {
            $table->dropUnique(['area_id', 'nome']);
        });
    }
};
