<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quesito extra da rubrica: o Projeto de Continuação (item 7.9 do edital).
     * Só aparece para o avaliador quando o projeto anexou o documento; nos
     * demais fica nulo e o quesito "projeto de pesquisa" entra sozinho na soma.
     *
     * Quando existe, a nota dos dois documentos é a MÉDIA entre eles — assim o
     * teto da avaliação continua o mesmo para todo mundo (ver a migration da
     * escala Likert) e o ranking segue comparável.
     */
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->unsignedTinyInteger('nota_continuidade')->nullable()->after('comentario_pesquisa');
            $table->text('comentario_continuidade')->nullable()->after('nota_continuidade');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropColumn(['nota_continuidade', 'comentario_continuidade']);
        });
    }
};
