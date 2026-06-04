<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CatalogoSeeder::class);

        // Orientador de exemplo para desenvolvimento (senha: password).
        User::factory()
            ->has(\App\Models\OrientadorProfile::factory(), 'orientadorProfile')
            ->create([
                'name' => 'Orientador Demo',
                'email' => 'orientador@fetecms.test',
                'role' => Role::Orientador,
            ]);
    }
}
