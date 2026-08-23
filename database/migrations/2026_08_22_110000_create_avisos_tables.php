<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aviso na tela: o admin publica um card que aparece para os orientadores
        // conectados. Um ativo por vez — publicar um novo encerra o anterior.
        Schema::create('avisos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 120);
            $table->text('mensagem');
            // Autor desnormalizado: o relatório precisa dizer quem publicou mesmo
            // que a conta do admin mude de nome ou saia depois.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('autor_nome');
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamps();

            $table->index('encerrado_em');
        });

        // Quem viu o card e quem o fechou. Uma linha por pessoa por aviso.
        Schema::create('aviso_visualizacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aviso_id')->constrained('avisos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('visto_em');
            $table->timestamp('fechado_em')->nullable();
            $table->timestamps();

            $table->unique(['aviso_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aviso_visualizacoes');
        Schema::dropIfExists('avisos');
    }
};
