<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 234 — o comitê especial pode avaliar **só o que foi designado** a ele.
 *
 * `edicoes.presencial_comite_so_designados` (Parametrização → Avaliação
 * presencial → Comitê especial) liga a regra. Com ela ligada, o avaliador da
 * comissão que quer avaliar no estande um projeto fora das designações dele
 * **pede permissão**, e a organização decide em Comitê especial → Designações.
 * Um pedido por avaliador e projeto na edição: pedir de novo depois de uma
 * recusa reabre o mesmo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->boolean('presencial_comite_so_designados')->default(false);
        });

        Schema::create('pedidos_avaliacao_comite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->foreignId('avaliador_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pendente');
            $table->string('justificativa', 500)->nullable();
            $table->string('resposta', 500)->nullable();
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_em')->nullable();
            $table->timestamps();

            $table->unique(['edicao_id', 'projeto_id', 'avaliador_id']);
            $table->index(['edicao_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_avaliacao_comite');

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('presencial_comite_so_designados');
        });
    }
};
