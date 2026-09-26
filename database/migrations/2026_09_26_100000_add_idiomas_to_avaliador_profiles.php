<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idiomas em que o avaliador se declara apto a avaliar (Português, Espanhol,
 * Inglês).
 *
 * É **lista**, e não um campo único: quem fala três marca os três. Guardada em
 * JSON como as demais listas curtas do portal (`avisos.publicos`,
 * `edicoes.distribuicao_regras`) — uma tabela pivô custaria um join em toda
 * listagem de avaliador para guardar, no máximo, três códigos por pessoa.
 *
 * Nulável porque **quem já está cadastrado não teve como responder**: o campo
 * passa a ser obrigatório no cadastro novo e ao salvar o perfil, mas tornar a
 * coluna obrigatória aqui trancaria a base existente. Quem está sem idioma
 * declarado simplesmente não entra nos recortes por idioma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->json('idiomas')->nullable()->after('camiseta');
        });
    }

    public function down(): void
    {
        Schema::table('avaliador_profiles', function (Blueprint $table) {
            $table->dropColumn('idiomas');
        });
    }
};
