<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Remove a área "Multidisciplinar" de bancos já semeados (o seeder não a cria mais).
 * As subáreas caem por cascata; projetos/perfis que a referenciavam ficam com a FK
 * nula (nullOnDelete). Em banco novo é no-op. Limpa o cache do catálogo de áreas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('areas')->where('nome', 'Multidisciplinar')->delete();
        Cache::forget('catalogo.areas');
    }

    public function down(): void
    {
        DB::table('areas')->insertOrIgnore([
            'nome' => 'Multidisciplinar', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::forget('catalogo.areas');
    }
};
