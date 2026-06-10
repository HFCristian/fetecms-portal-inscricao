<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;

class AdminService
{
    /** Cria um novo administrador (somente outro admin pode acionar — ver rota role:admin). */
    public function register(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => Role::Admin,
            'is_active' => true,
        ]);
    }
}
