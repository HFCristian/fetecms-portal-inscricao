<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 103 — presença e retirada de kit, pessoa a pessoa.
 *
 * O balcão passou a registrar duas coisas que antes não cabiam em lugar nenhum:
 *
 * - **quem apareceu**. Marcada a ausência, os documentos daquela pessoa deixam
 *   de ser exigidos — não faz sentido conferir o RG de quem não veio —, e o
 *   projeto é credenciado assim mesmo. A pessoa que chegar depois é conferida
 *   numa segunda passada, sem refazer o resto.
 * - **quem levou o kit de quem**. O kit é por pessoa, mas quase nunca cada uma
 *   vai ao balcão: um aluno retira o dele e o de dois colegas, e os que
 *   sobraram são retirados mais tarde, por outra pessoa e em outro horário.
 *   Por isso o responsável e o horário ficam em CADA linha, e não uma vez só
 *   no credenciamento.
 *
 * O nome vai desnormalizado pelo mesmo motivo de `credenciamento_documentos`:
 * a equipe pode mudar depois do evento, e o registro precisa continuar dizendo
 * quem estava lá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credenciamento_pessoas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credenciamento_id')->constrained('credenciamentos')->cascadeOnDelete();
            $table->string('pessoa_tipo');       // aluno | orientador | coorientador
            $table->unsignedBigInteger('pessoa_id')->nullable();
            $table->string('pessoa_nome');
            // Presente é o caso comum: quem não foi marcado veio ao balcão.
            $table->boolean('presente')->default(true);

            // Retirada do kit desta pessoa: quem levou e quando.
            $table->timestamp('kit_retirado_em')->nullable();
            $table->string('kit_retirado_por_tipo')->nullable();
            $table->unsignedBigInteger('kit_retirado_por_id')->nullable();
            $table->string('kit_retirado_por_nome')->nullable();
            $table->foreignId('kit_registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['credenciamento_id', 'pessoa_tipo', 'pessoa_id'],
                'credenciamento_pessoa_unica',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciamento_pessoas');
    }
};
