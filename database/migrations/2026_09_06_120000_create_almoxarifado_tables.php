<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 104 — Almoxarifado: a guarda de volumes dos finalistas durante a feira.
 *
 * A equipe chega com mochila, maquete e material de montagem e não tem onde
 * deixar isso enquanto circula pelo evento. O balcão do almoxarifado guarda, e
 * o que precisa ficar registrado é **de quem** é cada volume e **quem** o levou
 * de volta — quase nunca a mesma pessoa que deixou, e quase nunca tudo de uma
 * vez.
 *
 * - `almoxarifado_guardas`: um atendimento de guarda. Aponta para o projeto
 *   (finalista da lista vigente) e guarda quem deixou, desnormalizado, pelo
 *   mesmo motivo do credenciamento: a equipe pode mudar depois do evento.
 * - `almoxarifado_itens`: **um item por linha**, porque é o item que é retirado.
 *   A retirada parcial existe justamente porque o dono de um volume aparece
 *   antes dos outros; guardar só uma contagem impediria dizer o que saiu.
 *
 * `demo` separa o ensaio do balcão do registro de verdade, como em
 * `listas_finais`: no modo de teste o almoxarifado só enxerga os seus próprios
 * registros, então treinar nunca devolve o material de um finalista.
 *
 * A exclusão é **soft delete**: o registro sai da tela, mas a trilha de
 * Registros continua podendo apontar para ele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('almoxarifado_guardas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->nullable()->constrained('edicoes')->nullOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();

            // Quem deixou o material guardado: sempre gente do projeto.
            $table->string('responsavel_tipo');   // aluno | orientador | coorientador
            $table->unsignedBigInteger('responsavel_id')->nullable();
            $table->string('responsavel_nome');

            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registrado_em');
            // Ensaio do balcão: não se mistura com o material de verdade.
            $table->boolean('demo')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['edicao_id', 'demo']);
        });

        Schema::create('almoxarifado_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guarda_id')->constrained('almoxarifado_guardas')->cascadeOnDelete();
            $table->string('descricao');

            // Retirada: quem levou este item e quando. Nulo = ainda guardado.
            $table->timestamp('retirado_em')->nullable();
            $table->string('retirado_por_tipo')->nullable();
            $table->unsignedBigInteger('retirado_por_id')->nullable();
            $table->string('retirado_por_nome')->nullable();
            $table->foreignId('retirada_registrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('almoxarifado_itens');
        Schema::dropIfExists('almoxarifado_guardas');
    }
};
