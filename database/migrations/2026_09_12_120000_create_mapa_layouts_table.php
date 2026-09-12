<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 120 — Mapa do Evento → **a planta interativa**.
 *
 * O desenho do ginásio (onde fica cada estande) é **da edição** e **versionado**:
 * o layout nasce com a prancha da XVI FETECMS (`App\Support\PlantaEvento`), o
 * admin ajusta na tela e cada gravação cria uma versão nova. A `vigente` é a que
 * o mapa desenha; as anteriores ficam guardadas — no ano seguinte a montadora
 * muda o pavilhão, e ninguém quer perder o desenho que valeu no ano passado.
 *
 * `dados` guarda o layout inteiro em JSON (estandes com número e posição na
 * grade, mais as marcações de entrada). É um documento, não uma tabela de
 * pontos: ele só é lido e gravado por inteiro, e uma linha por estande faria
 * cada salvamento virar 230 inserts para nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapa_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->unsignedInteger('versao');
            $table->string('nome', 160);
            $table->json('dados');
            $table->boolean('vigente')->default(false);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['edicao_id', 'versao']);
            $table->index(['edicao_id', 'vigente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapa_layouts');
    }
};
