<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edicoes', function (Blueprint $table) {
            $table->id();
            $table->string('nome');            // ex.: XVI FETECMS
            $table->unsignedSmallInteger('ano');
            $table->boolean('inscricoes_abertas')->default(true);
            $table->date('inicio_em')->nullable();
            $table->date('fim_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edicoes');
    }
};
