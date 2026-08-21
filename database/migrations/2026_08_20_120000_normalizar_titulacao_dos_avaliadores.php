<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Titulação antiga → titulação com a situação explícita. */
    private const CONCLUIDAS = [
        'Especialização' => 'Especialização (concluída)',
        'Mestrado' => 'Mestrado (concluído)',
        'Doutorado' => 'Doutorado (concluído)',
    ];

    /**
     * O cadastro do avaliador passa a perguntar a titulação COM a situação
     * ("em andamento" ou "concluída"), porque quem já está cursando a
     * pós-graduação também pode avaliar. Os perfis antigos foram preenchidos
     * quando só existia a titulação concluída — é assim que ficam registrados.
     */
    public function up(): void
    {
        foreach (self::CONCLUIDAS as $antiga => $nova) {
            DB::table('avaliador_profiles')->where('titulacao', $antiga)->update(['titulacao' => $nova]);
        }
    }

    public function down(): void
    {
        foreach (self::CONCLUIDAS as $antiga => $nova) {
            DB::table('avaliador_profiles')->where('titulacao', $nova)->update(['titulacao' => $antiga]);
        }
    }
};
