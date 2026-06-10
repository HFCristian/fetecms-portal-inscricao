<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coorientadores', function (Blueprint $table) {
            $table->id();
            // unique => no máximo 1 coorientador por projeto.
            $table->foreignId('projeto_id')->unique()->constrained('projetos')->cascadeOnDelete();

            $table->string('nome');
            $table->string('email');
            $table->string('cpf', 11);
            $table->string('telefone', 20)->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('genero')->nullable();
            $table->string('camiseta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coorientadores');
    }
};
