<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Texto dos e-mails automáticos, editável pelo admin em Comunicação →
     * Modelos de e-mail.
     *
     * A tabela guarda só o que foi CUSTOMIZADO: sem linha, vale o texto de
     * fábrica do enum App\Enums\ModeloEmail. Restaurar o padrão é apagar a linha.
     */
    public function up(): void
    {
        Schema::create('modelos_email', function (Blueprint $table) {
            $table->id();
            $table->string('chave')->unique();
            $table->string('assunto');
            $table->text('corpo');

            // Quem editou por último (a tela mostra; o histórico fica em Registros).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('autor_nome')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modelos_email');
    }
};
