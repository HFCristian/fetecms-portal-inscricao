<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código do projeto na lista final, **congelado** (Sprint 159).
 *
 * O código (FET.AGR-001) era calculado na hora, a cada leitura da lista: a
 * numeração recomeça em cada categoria+área e segue a ordem alfabética dos
 * títulos, então incluir um projeto empurrava o número dos que vinham depois.
 * Enquanto o código só existia no TXT isso não importava; depois de ele ir por
 * e-mail para cada finalista, mudar seria mandar a equipe ao balcão com um
 * número que não é mais o dela.
 *
 * Ao enviar os códigos, cada projeto da lista grava o seu
 * (`lista_final_projetos.codigo`), e daí em diante ele não muda. Projeto que
 * entra depois ganha o **próximo número livre** do seu grupo.
 * `listas_finais.codigos_enviados_em` e `codigos_mala_id` lembram o envio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lista_final_projetos', function (Blueprint $table) {
            $table->string('codigo', 30)->nullable()->after('manual');
        });

        Schema::table('listas_finais', function (Blueprint $table) {
            $table->timestamp('codigos_congelados_em')->nullable()->after('versao');
            $table->timestamp('codigos_enviados_em')->nullable()->after('codigos_congelados_em');
            $table->foreignId('codigos_mala_id')->nullable()->after('codigos_enviados_em')
                ->constrained('malas_diretas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('listas_finais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('codigos_mala_id');
            $table->dropColumn(['codigos_congelados_em', 'codigos_enviados_em']);
        });

        Schema::table('lista_final_projetos', function (Blueprint $table) {
            $table->dropColumn('codigo');
        });
    }
};
