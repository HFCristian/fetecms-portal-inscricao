<?php

use App\Enums\StatusConversa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chat de suporte: uma conversa (thread) por usuário (orientador/avaliador),
        // contendo várias mensagens trocadas com o suporte (admin).
        Schema::create('conversas', function (Blueprint $table) {
            $table->id();
            // unique => no máximo 1 conversa (thread contínua) por usuário.
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status')->default(StatusConversa::NaoVisualizada->value);
            // Momento da última mensagem enviada pelo usuário (base do "há quanto tempo"
            // e do que está pendente de resposta).
            $table->timestamp('ultima_mensagem_em')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('conversas')->cascadeOnDelete();
            // Quem escreveu: 'usuario' (orientador/avaliador) ou 'suporte' (admin).
            $table->string('autor', 20);
            // Admin que respondeu (auditoria); nulo para mensagens do usuário.
            $table->foreignId('autor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('corpo');
            $table->timestamps();

            $table->index('conversa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens');
        Schema::dropIfExists('conversas');
    }
};
