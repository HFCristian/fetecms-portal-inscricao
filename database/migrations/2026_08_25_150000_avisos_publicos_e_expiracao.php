<?php

use App\Enums\PublicoMala;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos na tela deixam de ser sempre "para todos os orientadores":
 *
 * - `publicos`: os mesmos recortes da mala direta, combináveis. Os avisos que
 *   já existiam viram "todos os orientadores", que era o comportamento antigo;
 * - `expira_em`: data em que o card sai da tela sozinho, sem o admin encerrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avisos', function (Blueprint $table) {
            $table->json('publicos')->nullable();
            $table->timestamp('expira_em')->nullable();
        });

        DB::table('avisos')->update([
            'publicos' => json_encode([PublicoMala::Orientadores->value]),
        ]);
    }

    public function down(): void
    {
        Schema::table('avisos', function (Blueprint $table) {
            $table->dropColumn(['publicos', 'expira_em']);
        });
    }
};
