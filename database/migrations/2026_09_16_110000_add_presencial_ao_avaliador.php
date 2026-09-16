<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 131 — a **intenção** do avaliador de participar presencialmente da
 * feira, e o texto que a organização mostra a quem disser que sim.
 *
 * `presencial` é de três estados de propósito: **nulo** é "ainda não
 * respondeu", que é diferente de "não vai". A organização precisa dessa
 * diferença para saber quem cobrar — quem nunca abriu a aba não é a mesma
 * coisa que quem recusou.
 *
 * `edicoes.info_avaliacao_presencial` é o que aparece para quem aceitou (local,
 * horário de chegada, o que levar). Fica na edição, como os prazos e os
 * limites: muda de um ano para o outro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->boolean('presencial')->nullable()->after('comissao_especial');
            $table->timestamp('presencial_em')->nullable()->after('presencial');
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->text('info_avaliacao_presencial')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->dropColumn(['presencial', 'presencial_em']);
        });

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('info_avaliacao_presencial');
        });
    }
};
