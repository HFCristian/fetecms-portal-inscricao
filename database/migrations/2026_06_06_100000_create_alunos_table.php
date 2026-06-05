<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alunos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();

            // Dados pessoais (cadastro5)
            $table->string('nome');
            $table->string('email');
            $table->string('cpf', 11);
            $table->string('telefone', 20)->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('genero')->nullable();
            $table->string('etnia')->nullable();
            $table->string('camiseta')->nullable();

            // Dados acadêmicos (cadastro5)
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->nullOnDelete();
            $table->string('modalidade')->nullable();   // fundamental | medio | tecnico
            $table->string('ano_escolar')->nullable();
            $table->string('periodo')->nullable();
            $table->string('graduacao_pretendida')->nullable();
            $table->boolean('bolsista')->default(false);
            $table->boolean('clube_ciencias')->default(false);
            $table->boolean('autorizacao_menor')->default(false);

            $table->timestamps();

            // E-mail e CPF únicos dentro do mesmo projeto.
            $table->unique(['projeto_id', 'email']);
            $table->unique(['projeto_id', 'cpf']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alunos');
    }
};
