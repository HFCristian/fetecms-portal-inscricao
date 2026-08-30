<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 66 — o sistema passa a viver várias edições da feira ao mesmo tempo.
 *
 * - `edicoes.padrao`: a edição que vale para quem não escolheu nenhuma. É ela
 *   que o cadastro público, os e-mails e as telas assumem. Só uma por vez (a
 *   regra é aplicada no `EdicaoService`).
 * - `users.edicao_id`: a edição que a pessoa está olhando agora. Nulo = segue a
 *   padrão, que é o comportamento de sempre.
 *
 * O backfill preserva o que existia: vira padrão a mesma edição que
 * `Edicao::atual()` devolvia antes (a de inscrições abertas, mais recente), e
 * todo projeto sem edição é adotado por ela — antes desta sprint só havia uma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->boolean('padrao')->default(false)->after('ano');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('edicao_id')->nullable()->after('is_demo')
                ->constrained('edicoes')->nullOnDelete();
        });

        $padrao = DB::table('edicoes')
            ->where('inscricoes_abertas', true)
            ->orderByDesc('ano')
            ->value('id')
            ?? DB::table('edicoes')->orderByDesc('ano')->value('id');

        if ($padrao !== null) {
            DB::table('edicoes')->where('id', $padrao)->update(['padrao' => true]);
            DB::table('projetos')->whereNull('edicao_id')->update(['edicao_id' => $padrao]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edicao_id');
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('padrao');
        });
    }
};
