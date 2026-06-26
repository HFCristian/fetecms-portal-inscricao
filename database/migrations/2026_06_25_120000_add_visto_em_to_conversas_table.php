<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recibo de leitura ("visto"): quando cada lado viu a conversa pela última
        // vez. Uma mensagem foi vista pelo outro lado se ele visualizou em data
        // igual/posterior ao envio. Independente do `status` (que segue o workflow).
        Schema::table('conversas', function (Blueprint $table) {
            $table->timestamp('usuario_visto_em')->nullable()->after('ultima_mensagem_em');
            $table->timestamp('suporte_visto_em')->nullable()->after('usuario_visto_em');
        });
    }

    public function down(): void
    {
        Schema::table('conversas', function (Blueprint $table) {
            $table->dropColumn(['usuario_visto_em', 'suporte_visto_em']);
        });
    }
};
