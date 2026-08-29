<?php

use App\Support\Capitais;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca as capitais no catálogo de cidades.
     *
     * É o que permite a lista final reservar vagas para o "interior": interior
     * é todo projeto cuja cidade NÃO é a capital do próprio estado — regra que
     * vale também para projetos de fora de MS.
     */
    public function up(): void
    {
        Schema::table('cidades', function (Blueprint $table) {
            $table->boolean('capital')->default(false)->index();
        });

        foreach (Capitais::porUf() as $uf => $nome) {
            DB::table('cidades')
                ->whereIn('estado_id', DB::table('estados')->where('uf', $uf)->select('id'))
                ->where('nome', $nome)
                ->update(['capital' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('cidades', function (Blueprint $table) {
            $table->dropColumn('capital');
        });
    }
};
