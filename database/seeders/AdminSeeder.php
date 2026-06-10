<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Admin padrão para bootstrap. Em produção, troque a senha após o 1º acesso.
        User::firstOrCreate(
            ['email' => 'admin@fetecms.test'],
            [
                'name' => 'Administrador',
                'password' => 'password',
                'role' => Role::Admin,
                'is_active' => true,
            ],
        );
    }
}
