<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AvaliadorService
{
    private const PROFILE_FIELDS = ['cpf', 'titulacao', 'area_id', 'subarea_id'];

    /** Cria o usuário (role avaliador) e o perfil de avaliador numa transação. */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => Role::Avaliador,
                'is_active' => true,
            ]);

            $user->avaliadorProfile()->create(Arr::only($data, self::PROFILE_FIELDS));

            return $user->load('avaliadorProfile.area', 'avaliadorProfile.subarea');
        });
    }
}
