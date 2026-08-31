<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 88 — lista final de demonstração.
 *
 * O credenciamento só enxerga finalistas, e finalista é quem está na lista
 * final **vigente** da edição. Isso deixava o balcão impossível de treinar: ou
 * a organização já tinha publicado a lista de verdade, ou não havia ninguém
 * para credenciar — e publicar uma lista de mentira para ensaiar encerraria a
 * oficial.
 *
 * A coluna separa as duas. A lista **demo** convive com a oficial (cada uma tem
 * a sua vigente) e só aparece para quem está com o **modo de teste** ligado, que
 * é privilégio de conta demo. `ListaFinal::vigente()` continua devolvendo a
 * oficial para todo o resto do portal — ranking, registros e o próprio balcão
 * com o modo de teste desligado.
 *
 * `php artisan demo:credenciamento` é quem monta essa lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listas_finais', function (Blueprint $table) {
            $table->boolean('demo')->default(false)->after('vigente');
            $table->index(['edicao_id', 'demo', 'vigente']);
        });
    }

    public function down(): void
    {
        Schema::table('listas_finais', function (Blueprint $table) {
            $table->dropIndex(['edicao_id', 'demo', 'vigente']);
            $table->dropColumn('demo');
        });
    }
};
