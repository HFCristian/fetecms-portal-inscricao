<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Area;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([CatalogoSeeder::class, AdminSeeder::class]);

        // Orientador e avaliador de exemplo (senha: password).
        // Criados SEM factory de propósito: em produção roda-se `composer install --no-dev`,
        // e o fakerphp/faker (que fornece fake()) é require-dev — factories quebrariam lá.
        $orientador = User::firstOrCreate(
            ['email' => 'orientador@fetecms.test'],
            ['name' => 'Orientador Demo', 'password' => 'password', 'role' => Role::Orientador, 'is_active' => true],
        );
        $orientador->orientadorProfile()->firstOrCreate([], [
            'cpf' => '52998224725',
            'telefone' => '67999990000',
            'data_nascimento' => '1985-05-20',
            'titulacao' => 'Doutorado',
            'pais' => 'BR',
        ]);

        $avaliador = User::firstOrCreate(
            ['email' => 'avaliador@fetecms.test'],
            ['name' => 'Avaliador Demo', 'password' => 'password', 'role' => Role::Avaliador, 'is_active' => true],
        );
        $avaliador->avaliadorProfile()->firstOrCreate([], [
            'cpf' => '71428793860',
            'titulacao' => 'Doutorado',
            'area_id' => Area::query()->value('id'),
        ]);
    }
}
