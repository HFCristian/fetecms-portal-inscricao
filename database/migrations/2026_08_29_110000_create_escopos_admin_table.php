<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 67 — escopos de admin.
 *
 * `escopos_admin` é o perfil de acesso: um nome e a lista de abas do menu que
 * ele abre. `admin_escopos` liga um admin a um escopo **numa edição** — a mesma
 * pessoa pode ter recortes diferentes em cada ano da feira.
 *
 * Sem linha em `admin_escopos` para a edição em curso, o admin tem acesso
 * total: é o comportamento de antes desta sprint e impede que criar uma edição
 * nova tranque a equipe inteira para fora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escopos_admin', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->json('abas');              // list<AbaAdmin::value>
            $table->timestamps();
        });

        Schema::create('admin_escopos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->foreignId('escopo_admin_id')->constrained('escopos_admin')->cascadeOnDelete();
            $table->timestamps();

            // Um escopo por admin em cada edição.
            $table->unique(['user_id', 'edicao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_escopos');
        Schema::dropIfExists('escopos_admin');
    }
};
