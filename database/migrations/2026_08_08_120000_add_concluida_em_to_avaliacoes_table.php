<?php

use App\Enums\StatusAvaliacao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data em que a avaliação foi enviada. `updated_at` não serve como "data da
     * avaliação" porque muda em qualquer escrita posterior — e os painéis do
     * admin (reclassificações e ranking) filtram por esse período.
     */
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->timestamp('concluida_em')->nullable()->after('rascunho_em');
            $table->index(['status', 'concluida_em']);
        });

        // Avaliações já concluídas antes desta coluna: o melhor palpite disponível
        // é o updated_at, já que concluir era a última escrita do fluxo.
        DB::table('avaliacoes')
            ->where('status', StatusAvaliacao::Concluida->value)
            ->whereNull('concluida_em')
            ->update(['concluida_em' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropIndex(['status', 'concluida_em']);
            $table->dropColumn('concluida_em');
        });
    }
};
