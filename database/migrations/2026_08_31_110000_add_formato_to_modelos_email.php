<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 91 — modelos de e-mail com formatação.
 *
 * O texto dos e-mails automáticos passou a ser escrito no mesmo editor rico da
 * mala direta (negrito, itálico, sublinhado, traçado e listas), então o corpo
 * pode chegar como **HTML**. A coluna diz qual dos dois é.
 *
 * **`texto` é o padrão** e continua sendo o que os modelos de fábrica usam: os
 * textos do enum `App\Enums\ModeloEmail` são texto puro, e o corpo já
 * customizado antes desta sprint também. Assim nenhum e-mail muda de aparência
 * sozinho — só o que o admin reescrever no editor vira HTML.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modelos_email', function (Blueprint $table) {
            $table->string('formato', 10)->default('texto')->after('corpo');
        });
    }

    public function down(): void
    {
        Schema::table('modelos_email', function (Blueprint $table) {
            $table->dropColumn('formato');
        });
    }
};
