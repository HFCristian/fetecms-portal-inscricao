<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Instituições de ensino / escolas (catálogo). Dados semeados de forma
        // representativa; substituíveis por lista/importação real depois.
        Schema::create('instituicoes', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->foreignId('cidade_id')->nullable()->constrained('cidades')->nullOnDelete();
            $table->string('tipo')->nullable(); // publica_federal, publica_estadual, privada...
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instituicoes');
    }
};
