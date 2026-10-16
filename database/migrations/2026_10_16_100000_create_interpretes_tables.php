<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprints 214–215 — Avaliação presencial → **Intérpretes**.
 *
 * - `interpretes`: o cadastro, por edição. Obrigatórios só nome e CPF (o CPF
 *   não se repete na edição); data de nascimento e os tipos (línguas e
 *   atendimento a pessoa com deficiência) são opcionais. "Outra língua" e
 *   "outro atendimento" guardam o texto livre de quem marcou "Outros".
 * - `interprete_vinculos`: a quem o intérprete atende — o **projeto inteiro**
 *   (`pessoa_tipo` nulo) ou um **participante** (aluno, orientador ou
 *   coorientador; `pessoa_id` é o id na tabela daquele papel). O projeto vai
 *   sempre preenchido, mesmo no vínculo a um participante: é por ele que o
 *   intérprete chega ao estande e ao turno, e por isso a designação no mapa
 *   **segue o projeto** quando ele muda de lugar. `origem` separa o vínculo do
 *   cadastro, o do mapa (designação no estande) e o do pedido de suporte.
 * - `interprete_checkins`: a chegada dele em cada **ocorrência** do evento
 *   (dia × turno), uma por ocorrência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interpretes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->string('nome', 200);
            $table->string('cpf', 11);
            $table->date('data_nascimento')->nullable();
            $table->json('tipos')->nullable();
            $table->string('outra_lingua', 120)->nullable();
            $table->string('outro_pcd', 120)->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['edicao_id', 'cpf']);
        });

        Schema::create('interprete_vinculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interprete_id')->constrained('interpretes')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->string('pessoa_tipo', 20)->nullable();
            $table->unsignedBigInteger('pessoa_id')->nullable();
            $table->string('origem', 20)->default('cadastro');
            $table->timestamps();

            $table->index(['projeto_id']);
            $table->index(['interprete_id', 'projeto_id']);
        });

        Schema::create('interprete_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interprete_id')->constrained('interpretes')->cascadeOnDelete();
            $table->date('dia');
            $table->string('turno', 1);
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['interprete_id', 'dia', 'turno']);
            $table->index(['dia', 'turno']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interprete_checkins');
        Schema::dropIfExists('interprete_vinculos');
        Schema::dropIfExists('interpretes');
    }
};
