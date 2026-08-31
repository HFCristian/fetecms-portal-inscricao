<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 92 — ordem das abas do menu do admin.
 *
 * A ordem do menu era a ordem em que as abas foram nascendo no código (o enum
 * `App\Enums\AbaAdmin`), e ela não acompanha o ano da feira: no mês do evento,
 * Credenciamento deveria estar no topo; na inscrição, Projetos. A coluna guarda
 * a ordem que a organização escolheu, **por edição** — trocar de edição troca a
 * ordem junto, como já acontece com prazos, limites e regras.
 *
 * **Nulo é a ordem canônica do enum**, que é o comportamento de sempre. Aba que
 * não estiver na lista (uma criada depois que a ordem foi salva) entra no
 * **fim**, na ordem do enum: acrescentar uma aba no código nunca a faz sumir do
 * menu de quem já configurou.
 *
 * Isto é preferência de tela, não regra do edital: não vai para a trilha de
 * Registros, que existe para o que muda o resultado da feira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->json('ordem_abas')->nullable()->after('itens_credenciamento');
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('ordem_abas');
        });
    }
};
