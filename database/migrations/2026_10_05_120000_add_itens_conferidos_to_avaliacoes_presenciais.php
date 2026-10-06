<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 171 — os **itens da checagem** na avaliação do estande.
 *
 * O avaliador marca, item a item do catálogo da checagem de estandes (banner,
 * diário de bordo, material combinado…), se encontrou cada um no estande:
 * `{"<item_id>": "presente" | "ausente"}`. É checklist, **não vale nota**: a
 * rubrica presencial continua fechando em 10 — o que se registra é o que o
 * avaliador viu na hora da visita, que nem sempre é o que a checagem viu de
 * manhã.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->json('itens_conferidos')->nullable()->after('comentario');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes_presenciais', function (Blueprint $table) {
            $table->dropColumn('itens_conferidos');
        });
    }
};
