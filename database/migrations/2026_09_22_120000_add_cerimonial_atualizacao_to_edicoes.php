<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De quantos em quantos segundos a **Visão Geral do cerimonial** se atualiza
 * sozinha.
 *
 * O painel costuma ficar aberto num tablet da organização ou projetado enquanto
 * vários balcões fazem check-in em paralelo, então ele precisa andar sem
 * ninguém apertar nada. Não há WebSocket no projeto: é polling, como os avisos
 * e o mapa do comitê.
 *
 * O intervalo é **da edição**, como os prazos e os limites — numa feira grande
 * vale recarregar de 10 em 10 segundos, numa pequena isso é bater no servidor à
 * toa. **Nulo desliga** a atualização automática e deixa só o botão de
 * atualizar; o padrão de 30s é o meio-termo com que a tela nasce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->unsignedSmallInteger('cerimonial_atualizacao_segundos')->nullable()->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('cerimonial_atualizacao_segundos');
        });
    }
};
