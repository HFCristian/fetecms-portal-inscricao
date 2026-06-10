<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Area;
use App\Models\AvaliadorProfile;
use App\Models\OrientadorProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([CatalogoSeeder::class, AdminSeeder::class]);

        // Orientador de exemplo para desenvolvimento (senha: password).
        User::factory()
            ->has(OrientadorProfile::factory(), 'orientadorProfile')
            ->create([
                'name' => 'Orientador Demo',
                'email' => 'orientador@fetecms.test',
                'role' => Role::Orientador,
            ]);

        // Avaliador de exemplo para desenvolvimento (senha: password).
        $area = Area::query()->first();
        User::factory()
            ->has(AvaliadorProfile::factory()->state(['area_id' => $area?->id]), 'avaliadorProfile')
            ->create([
                'name' => 'Avaliador Demo',
                'email' => 'avaliador@fetecms.test',
                'role' => Role::Avaliador,
            ]);
    }
}
