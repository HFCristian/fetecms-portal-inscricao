<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lista global de palavras-chave, compartilhada entre todos os orientadores.
        Schema::create('palavras_chave', function (Blueprint $table) {
            $table->id();
            $table->string('texto')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('palavras_chave');
    }
};
