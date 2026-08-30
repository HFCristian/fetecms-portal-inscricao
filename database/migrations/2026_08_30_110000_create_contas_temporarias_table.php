<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 82 — contas temporárias de credenciamento.
 *
 * O balcão do evento é atendido por gente que não faz parte da organização o ano
 * todo (estudantes de um curso, geralmente). Em vez de virarem administradores
 * de verdade, elas ganham uma conta com **prazo de validade** e acesso a uma
 * única aba: Credenciamento.
 *
 * A conta em si é um `users` com `role = admin` — é o que faz o menu, o
 * middleware `aba:` e todo o resto do portal funcionarem sem exceções novas. O
 * que a restringe é a linha desta tabela: `User::ehContaTemporaria()` faz
 * `abasPermitidas()` devolver só "credenciamento", passando por cima de
 * qualquer escopo. A trava é dura de propósito — conta de prazo curto não
 * deveria depender de alguém lembrar de configurar o escopo certo.
 *
 * Vencido o prazo, a conta é **desativada**, não apagada: reativar é só informar
 * um prazo novo, sem recadastrar nome, e-mail, CPF e curso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_temporarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('cpf', 11);
            $table->string('curso');
            // Quando o acesso vence. Passada a data, a conta é desativada na
            // próxima leitura da lista (ou na tentativa de login).
            $table->timestamp('expira_em');
            // Quem criou — o histórico some se o admin for excluído, mas a conta
            // temporária continua de pé.
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('expira_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contas_temporarias');
    }
};
