<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Padroniza o endereço do orientador igual ao do projeto: no Brasil usa o catálogo
 * (estado_id / cidade_id); fora do Brasil usa texto livre (estado_nome / cidade_nome).
 * Substitui as colunas-texto antigas `estado` / `cidade`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->foreignId('estado_id')->nullable()->after('bairro')->constrained('estados')->nullOnDelete();
            $table->foreignId('cidade_id')->nullable()->after('estado_id')->constrained('cidades')->nullOnDelete();
            $table->string('estado_nome')->nullable()->after('cidade_id');
            $table->string('cidade_nome')->nullable()->after('estado_nome');
        });

        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->dropColumn(['cidade', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->string('cidade')->nullable();
            $table->string('estado')->nullable();
        });

        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('estado_id');
            $table->dropConstrainedForeignId('cidade_id');
            $table->dropColumn(['estado_nome', 'cidade_nome']);
        });
    }
};
