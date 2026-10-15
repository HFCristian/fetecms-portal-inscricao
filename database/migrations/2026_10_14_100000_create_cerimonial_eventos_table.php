<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cerimonial → **eventos de cerimônia** (Sprint 207).
 *
 * A feira faz mais de uma premiação — uma para a FETECMS, outra para a FETEC Jr
 * e a FETECMS FUNDECT —, com o mesmo funcionamento e públicos diferentes. Cada
 * evento tem um nome e o seu **público**: as categorias dos projetos
 * finalistas que ele recebe (`categorias`, JSON). Uma categoria pode estar em
 * mais de um evento — uma cerimônia geral ao lado das duas, por exemplo.
 *
 * O check-in passa a ser **por evento** (`cerimonial_checkins.evento_id`): a
 * mesma pessoa pode entrar nas duas cerimônias, e cada uma conta a sua sala. A
 * unicidade ganha o evento.
 *
 * Check-ins que já existiam não podem ficar sem dono: cada edição que tem algum
 * ganha o evento "Cerimônia de premiação", com todas as categorias, e eles vão
 * para ele. A coluna fica anulável no esquema (alterar a nulidade no SQLite
 * reconstrói a tabela), mas o serviço sempre a preenche.
 */
return new class extends Migration
{
    private const TODAS = ['fetecms', 'fetec_jr', 'fetecms_fundect'];

    public function up(): void
    {
        Schema::create('cerimonial_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edicao_id')->constrained('edicoes')->cascadeOnDelete();
            $table->string('nome', 120);
            $table->json('categorias');
            $table->timestamps();

            $table->index('edicao_id');
        });

        Schema::table('cerimonial_checkins', function (Blueprint $table) {
            $table->foreignId('evento_id')->nullable()->after('edicao_id')
                ->constrained('cerimonial_eventos')->cascadeOnDelete();
        });

        $edicoes = DB::table('cerimonial_checkins')->distinct()->pluck('edicao_id');

        foreach ($edicoes as $edicaoId) {
            $eventoId = DB::table('cerimonial_eventos')->insertGetId([
                'edicao_id' => $edicaoId,
                'nome' => 'Cerimônia de premiação',
                'categorias' => json_encode(self::TODAS),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('cerimonial_checkins')->where('edicao_id', $edicaoId)->update(['evento_id' => $eventoId]);
        }

        Schema::table('cerimonial_checkins', function (Blueprint $table) {
            $table->dropUnique('cerimonial_checkin_pessoa_unica');
            // Uma pessoa entra uma vez **em cada evento**.
            $table->unique(['evento_id', 'projeto_id', 'papel', 'participante_id', 'demo'], 'cerimonial_checkin_pessoa_evento_unica');
        });
    }

    public function down(): void
    {
        Schema::table('cerimonial_checkins', function (Blueprint $table) {
            $table->dropUnique('cerimonial_checkin_pessoa_evento_unica');
        });

        // Volta a uma linha por pessoa: fica a primeira entrada de cada uma.
        $repetidas = DB::table('cerimonial_checkins')
            ->select('projeto_id', 'papel', 'participante_id', 'demo', DB::raw('MIN(id) as manter'))
            ->groupBy('projeto_id', 'papel', 'participante_id', 'demo')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($repetidas as $r) {
            DB::table('cerimonial_checkins')
                ->where('projeto_id', $r->projeto_id)->where('papel', $r->papel)
                ->where('participante_id', $r->participante_id)->where('demo', $r->demo)
                ->where('id', '<>', $r->manter)
                ->delete();
        }

        Schema::table('cerimonial_checkins', function (Blueprint $table) {
            $table->unique(['projeto_id', 'papel', 'participante_id', 'demo'], 'cerimonial_checkin_pessoa_unica');
            $table->dropConstrainedForeignId('evento_id');
        });

        Schema::dropIfExists('cerimonial_eventos');
    }
};
