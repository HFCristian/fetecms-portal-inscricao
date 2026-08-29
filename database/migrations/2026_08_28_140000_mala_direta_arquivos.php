<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arquivos da mala direta: as imagens que entram no corpo da mensagem e os
     * anexos que vão junto do e-mail.
     *
     * O arquivo é enviado ANTES de a mala existir (o admin ainda está
     * escrevendo), então nasce sem `mala_direta_id` e é vinculado no disparo.
     * Órfão de mais de um dia é apagado no próximo upload.
     */
    public function up(): void
    {
        Schema::create('mala_direta_arquivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mala_direta_id')->nullable()->constrained('malas_diretas')->cascadeOnDelete();
            // imagem (corpo da mensagem) | anexo (arquivo junto do e-mail).
            $table->string('tipo');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('nome_original');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('tamanho_bytes')->default(0);
            $table->timestamps();

            $table->index(['mala_direta_id', 'tipo']);
        });

        Schema::table('malas_diretas', function (Blueprint $table) {
            // texto | html — as malas antigas continuam em texto puro.
            $table->string('formato')->default('texto');
        });
    }

    public function down(): void
    {
        Schema::table('malas_diretas', function (Blueprint $table) {
            $table->dropColumn('formato');
        });

        Schema::dropIfExists('mala_direta_arquivos');
    }
};
