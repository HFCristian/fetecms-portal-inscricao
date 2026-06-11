<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instituições viram catálogo de verdade: ganham código do INEP (chave única para
 * importação idempotente) e zona. O orientador passa a referenciar a instituição por
 * FK (instituicao_id), igual ao projeto — em vez do texto livre antigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instituicoes', function (Blueprint $table) {
            $table->string('codigo_inep')->nullable()->unique()->after('id');
            $table->string('zona')->nullable()->after('tipo');
        });

        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->foreignId('instituicao_id')->nullable()->after('pcd')->constrained('instituicoes')->nullOnDelete();
        });

        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->dropColumn('instituicao');
        });
    }

    public function down(): void
    {
        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->string('instituicao')->nullable();
        });

        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instituicao_id');
        });

        Schema::table('instituicoes', function (Blueprint $table) {
            $table->dropUnique(['codigo_inep']);
            $table->dropColumn(['codigo_inep', 'zona']);
        });
    }
};
