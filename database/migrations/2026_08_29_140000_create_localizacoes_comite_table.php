<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 72 — Comitê especial → Transporte de comitê.
 *
 * `localizacoes_comite` é a **sessão do localizador**: quem ligou, quantas
 * pessoas estão com ela, de onde saiu, para onde vai e até quando o localizador
 * fica ligado. A última posição mora na própria linha — é o que o mapa lê.
 *
 * `localizacao_comite_pontos` é o **trajeto vivo**: os pontos que chegam de 5
 * em 5 segundos, apagados quando a sessão é encerrada ou vence. Guardar o
 * histórico completo seria geolocalização pessoal armazenada sem prazo, e a
 * tela não precisa dele depois que o grupo chega.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('localizacoes_comite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('edicao_id')->nullable()->constrained('edicoes')->nullOnDelete();

            // Passos 1 a 3 do assistente.
            $table->unsignedSmallInteger('pessoas')->default(1);
            $table->json('acompanhantes')->nullable();   // [{ nome, area }] — nomes são opcionais
            $table->string('transporte')->nullable();    // carro | van | onibus | a_pe | outro

            // Passos 4 e 5: de onde sai, para onde vai e por quanto tempo.
            $table->string('origem_nome')->nullable();
            $table->decimal('origem_lat', 10, 7)->nullable();
            $table->decimal('origem_lng', 10, 7)->nullable();
            $table->string('destino_nome');
            $table->decimal('destino_lat', 10, 7)->nullable();
            $table->decimal('destino_lng', 10, 7)->nullable();
            $table->timestamp('expira_em');
            $table->timestamp('encerrado_em')->nullable();

            // Última posição conhecida + a estimativa que veio com ela.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('precisao_m')->nullable();
            $table->unsignedInteger('distancia_m')->nullable();
            $table->unsignedInteger('duracao_s')->nullable();
            $table->timestamp('posicao_em')->nullable();

            $table->timestamps();

            $table->index(['edicao_id', 'encerrado_em']);
        });

        Schema::create('localizacao_comite_pontos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('localizacao_comite_id')->constrained('localizacoes_comite')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('registrado_em');

            $table->index(['localizacao_comite_id', 'registrado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('localizacao_comite_pontos');
        Schema::dropIfExists('localizacoes_comite');
    }
};
