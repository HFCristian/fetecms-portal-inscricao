<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPF do **administrador** (Sprint 163), para o certificado da organização.
 *
 * Orientador e avaliador guardam o CPF no perfil de cada papel; o admin não tem
 * perfil, e até aqui o portal não sabia o CPF de quem organiza a feira — que é
 * justamente o que o certificado da comissão organizadora pede. Opcional:
 * contas antigas continuam valendo, e o CSV sai com o campo em branco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cpf', 11)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cpf');
        });
    }
};
