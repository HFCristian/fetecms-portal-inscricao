<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orientador_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Dados pessoais (cadastro1)
            $table->string('cpf', 11)->unique();
            $table->string('telefone', 20);
            $table->date('data_nascimento');
            $table->string('genero')->nullable();
            $table->string('genero_outro')->nullable();
            $table->string('etnia')->nullable();
            $table->string('camiseta')->nullable();
            $table->boolean('pcd')->default(false);

            // Dados acadêmicos (cadastro2) — viram FK de catálogo na Sprint 2
            $table->string('instituicao')->nullable();
            $table->string('tipo_instituicao')->nullable();
            $table->string('vinculo')->nullable();
            $table->string('titulacao')->nullable();
            $table->string('curso_formacao')->nullable();
            $table->string('area_conhecimento')->nullable();
            $table->string('subarea')->nullable();
            $table->string('tempo_orientacao')->nullable();
            $table->string('vezes_fetec')->nullable();
            $table->boolean('ex_aluno_fetec')->default(false);

            // Endereço (cadastro3) — normaliza em tabela própria na Sprint 2
            $table->string('cep', 8)->nullable();
            $table->string('logradouro')->nullable();
            $table->string('numero')->nullable();
            $table->string('complemento')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->string('estado')->nullable();
            $table->string('pais')->default('BR');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orientador_profiles');
    }
};
