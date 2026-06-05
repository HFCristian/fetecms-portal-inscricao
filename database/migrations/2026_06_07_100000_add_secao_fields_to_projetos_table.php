<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projetos', function (Blueprint $table) {
            $table->string('feira_afiliada_nome')->nullable()->after('feira_afiliada');
            $table->boolean('necessita_termo_etica')->default(false)->after('feira_afiliada_nome');
            // Declaração de leitura do e-mail de comunicação (gate de submissão).
            $table->boolean('declaracao_email')->default(false)->after('email_comunicacao');
        });
    }

    public function down(): void
    {
        Schema::table('projetos', function (Blueprint $table) {
            $table->dropColumn(['feira_afiliada_nome', 'necessita_termo_etica', 'declaracao_email']);
        });
    }
};
