<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cadastro à espera da confirmação do e-mail (orientador e avaliador).
     *
     * O usuário só nasce quando o código de 6 dígitos é digitado: até lá, tudo
     * que a pessoa preencheu fica aqui, em `payload`. É o que permite ela
     * cadastrar o MESMO CPF de novo depois de errar o e-mail — nenhuma linha
     * chegou a ocupar `users`/`orientador_profiles`/`avaliador_profiles`.
     *
     * A senha entra em `payload` JÁ HASHEADA; em claro ela nunca é gravada.
     */
    public function up(): void
    {
        Schema::create('cadastros_pendentes', function (Blueprint $table) {
            $table->id();
            // orientador | avaliador (App\Enums\Role) — decide qual service cria a conta.
            $table->string('papel');
            $table->string('nome');
            $table->string('email')->index();
            // Segredo que o navegador guarda para confirmar/reenviar/trocar o e-mail.
            $table->string('token', 64)->unique();
            // Só o hash do código de 6 dígitos, como qualquer senha.
            $table->string('codigo_hash');
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->timestamp('expira_em');
            $table->timestamp('ultimo_envio_em')->nullable();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cadastros_pendentes');
    }
};
