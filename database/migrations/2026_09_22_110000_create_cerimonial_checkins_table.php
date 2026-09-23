<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aba **Cerimonial** — o check-in da cerimônia de premiação.
 *
 * É um balcão à parte do credenciamento: o credenciamento acontece na chegada
 * ao evento e confere documento; a cerimônia é outro momento, outra porta e
 * outra fila, e o que interessa nela é **quem já está na sala**. Por isso uma
 * pessoa faz check-in aqui mesmo sem ter passado pelo balcão de credenciamento.
 *
 * A linha é **por pessoa**, não por projeto: os cards precisam contar gente
 * (quantos alunos chegaram, quantas medalhas separar) e dizer nominalmente quem
 * falta. O projeto se deduz das linhas dos integrantes — é assim que a tela
 * distingue o projeto **parcialmente** presente do **completo**.
 *
 * A identidade da pessoa vem do mesmo trio do crachá (`App\Support\CodigoParticipante`):
 * `projeto_id` + `papel` (A/O/C) + `participante_id`. O papel é obrigatório
 * porque aluno, orientador e coorientador moram em tabelas diferentes e os ids
 * colidiriam. O `nome` fica **desnormalizado**: o card nominal precisa continuar
 * legível mesmo que a equipe mude depois, e o registro de auditoria também.
 *
 * `demo` isola o ensaio, como no almoxarifado: treinar o balcão não pode sujar
 * a contagem de quem realmente chegou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cerimonial_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            // A, O ou C — o papel do crachá.
            $table->string('papel', 1);
            $table->unsignedBigInteger('participante_id');
            $table->string('nome');
            $table->timestamp('checkin_em');
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('demo')->default(false);
            $table->timestamps();

            // Uma pessoa entra uma vez. O `demo` entra na chave para o ensaio e
            // a cerimônia de verdade não disputarem a mesma linha.
            $table->unique(['projeto_id', 'papel', 'participante_id', 'demo'], 'cerimonial_checkin_pessoa_unica');
            $table->index(['edicao_id', 'demo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cerimonial_checkins');
    }
};
