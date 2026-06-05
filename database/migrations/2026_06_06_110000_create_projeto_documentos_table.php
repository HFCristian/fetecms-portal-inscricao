<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projeto_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_id')->constrained('projetos')->cascadeOnDelete();
            $table->string('tipo')->default('plano_pesquisa'); // App\Enums\TipoDocumento
            $table->string('disk')->default('local');
            $table->string('path');                 // caminho interno (nunca exposto na API)
            $table->string('nome_original');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('tamanho_bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projeto_documentos');
    }
};
