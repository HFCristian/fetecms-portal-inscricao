<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rubrica da avaliação: três quesitos de 0 a 10 (vídeo de apresentação,
     * resumo e projeto de pesquisa), cada um com um campo opcional de
     * sugestões/comentários. A coluna `nota` deixa de ser a nota única 1–10 e
     * passa a guardar a SOMA dos três quesitos (0 a 30) — o tipo continua
     * servindo, pois unsignedTinyInteger vai até 255.
     */
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->unsignedTinyInteger('nota_video')->nullable()->after('status');
            $table->text('comentario_video')->nullable()->after('nota_video');
            $table->unsignedTinyInteger('nota_resumo')->nullable()->after('comentario_video');
            $table->text('comentario_resumo')->nullable()->after('nota_resumo');
            $table->unsignedTinyInteger('nota_pesquisa')->nullable()->after('comentario_resumo');
            $table->text('comentario_pesquisa')->nullable()->after('nota_pesquisa');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn([
                'nota_video', 'comentario_video',
                'nota_resumo', 'comentario_resumo',
                'nota_pesquisa', 'comentario_pesquisa',
            ]);
        });
    }
};
