<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 76 — RBAC: o admin passa a acumular **vários** escopos por edição.
 *
 * O desenho vira o de um Rule-Based Access Control: a *rule* é a aba do menu
 * (`App\Enums\AbaAdmin`), o *role* é o escopo (um nome + o conjunto de abas que
 * ele abre) e o usuário pode carregar **um ou mais** roles em cada edição — o
 * acesso dele é a **união** das abas de todos eles.
 *
 * A única mudança de esquema é a unicidade de `admin_escopos`: antes era um
 * escopo por (admin, edição); agora o par se repete, e o que não pode repetir é
 * o mesmo escopo duas vezes para a mesma pessoa na mesma edição.
 *
 * Nada precisa de backfill: quem tinha um escopo continua com exatamente ele, e
 * quem não tinha nenhum segue com acesso total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_escopos', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'edicao_id']);
            $table->unique(['user_id', 'edicao_id', 'escopo_admin_id'], 'admin_escopos_user_edicao_escopo_unique');
        });
    }

    public function down(): void
    {
        // Volta ao "um escopo por edição": mantém o de menor id em cada par e
        // descarta os demais, senão o índice único não pode ser recriado.
        $duplicados = DB::table('admin_escopos')
            ->selectRaw('MIN(id) as manter, user_id, edicao_id')
            ->groupBy('user_id', 'edicao_id')
            ->pluck('manter');

        DB::table('admin_escopos')
            ->whereNotIn('id', $duplicados)
            ->delete();

        Schema::table('admin_escopos', function (Blueprint $table) {
            $table->dropUnique('admin_escopos_user_edicao_escopo_unique');
            $table->unique(['user_id', 'edicao_id']);
        });
    }
};
