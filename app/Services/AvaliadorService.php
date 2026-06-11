<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AvaliadorService
{
    private const PROFILE_FIELDS = ['cpf', 'titulacao', 'area_id', 'subarea_id'];

    public function __construct(private readonly SubareaService $subareas) {}

    /** Cria o usuário (role avaliador) e o perfil de avaliador numa transação. */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $data = $this->resolverSubarea($data);

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

    /**
     * Subárea NOVA por texto (subarea_nome) sem id: cria/reaproveita a subárea
     * global na área escolhida e injeta o subarea_id resultante.
     */
    private function resolverSubarea(array $data): array
    {
        $temNome = ! empty($data['subarea_nome'] ?? null);
        $temArea = ! empty($data['area_id'] ?? null);

        if (empty($data['subarea_id'] ?? null) && $temNome && $temArea) {
            $data['subarea_id'] = $this->subareas
                ->firstOrCreateNaArea((int) $data['area_id'], (string) $data['subarea_nome'])
                ->id;
        }

        return $data;
    }
}
