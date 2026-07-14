<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marca se o usuário já fechou o balão de apresentação do chat (não mostrar mais).
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('chat_dica_dispensada')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('chat_dica_dispensada');
        });
    }
};
