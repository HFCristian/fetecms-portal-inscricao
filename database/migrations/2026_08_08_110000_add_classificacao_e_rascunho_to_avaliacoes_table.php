<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conferência da classificação do projeto pelo avaliador (área obrigatória,
     * subárea opcional) e marca de quando o rascunho da avaliação foi salvo.
     *
     * As sugestões apontam para o catálogo global de área/subárea — o mesmo dos
     * formulários do orientador. `nullOnDelete` para uma mescla/exclusão de
     * catálogo pelo admin não derrubar avaliações já feitas.
     */
    public function up(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->boolean('area_correta')->nullable()->after('comentario_pesquisa');
            $table->foreignId('area_sugerida_id')->nullable()->after('area_correta')
                ->constrained('areas')->nullOnDelete();
            $table->boolean('subarea_correta')->nullable()->after('area_sugerida_id');
            $table->foreignId('subarea_sugerida_id')->nullable()->after('subarea_correta')
                ->constrained('subareas')->nullOnDelete();

            // Preenchido a cada "salvar rascunho"; some quando a avaliação é enviada.
            $table->timestamp('rascunho_em')->nullable()->after('subarea_sugerida_id');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_sugerida_id');
            $table->dropConstrainedForeignId('subarea_sugerida_id');
            $table->dropColumn(['area_correta', 'subarea_correta', 'rascunho_em']);
        });
    }
};
