<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estado/cidade em texto livre, usados quando o país do projeto NÃO é o Brasil
        // (no Brasil usamos os catálogos via estado_id/cidade_id).
        Schema::table('projetos', function (Blueprint $table) {
            $table->string('estado_nome')->nullable()->after('estado_id');
            $table->string('cidade_nome')->nullable()->after('cidade_id');
        });
    }

    public function down(): void
    {
        Schema::table('projetos', function (Blueprint $table) {
            $table->dropColumn(['estado_nome', 'cidade_nome']);
        });
    }
};
