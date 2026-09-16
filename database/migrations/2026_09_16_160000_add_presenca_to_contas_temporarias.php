<?php

use App\Enums\StatusPresenca;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 136 — **presença** das contas temporárias.
 *
 * Cadastrar a equipe não é o mesmo que saber quem apareceu. No primeiro acesso
 * dentro do horário, a pessoa **marca presença**; o admin daquele setor
 * (credenciamento, almoxarifado, checagem) **aprova ou rejeita**, e só então a
 * conta abre a aba. Rejeitar exige **motivo escrito** — a pessoa está ali, na
 * frente de alguém, e "não pode entrar" sem explicação não se sustenta.
 *
 * Enquanto a presença não é aprovada a conta existe, está no prazo e não abre
 * nada: é a diferença entre ter crachá e estar de plantão.
 *
 * As contas que já existem entram como **aprovadas**: o portal está em uso, e
 * exigir presença retroativamente trancaria para fora um balcão em pleno
 * funcionamento no dia do deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_temporarias', function (Blueprint $table) {
            // Nulo = ainda não marcou presença.
            $table->string('presenca_status')->nullable()->after('expira_em');
            $table->timestamp('presenca_em')->nullable()->after('presenca_status');
            $table->foreignId('presenca_decidida_por')->nullable()->after('presenca_em')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('presenca_decidida_em')->nullable()->after('presenca_decidida_por');
            $table->string('presenca_motivo')->nullable()->after('presenca_decidida_em');
        });

        DB::table('contas_temporarias')->update([
            'presenca_status' => StatusPresenca::Aprovada->value,
            'presenca_decidida_em' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('contas_temporarias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('presenca_decidida_por');
            $table->dropColumn([
                'presenca_status', 'presenca_em', 'presenca_decidida_em', 'presenca_motivo',
            ]);
        });
    }
};
