<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class OrientadorService
{
    /**
     * Campos do perfil (cadastro1–3). O restante do payload (name/email/password)
     * pertence ao usuário.
     */
    private const PROFILE_FIELDS = [
        'cpf', 'telefone', 'data_nascimento', 'genero', 'genero_outro', 'etnia',
        'camiseta', 'pcd', 'instituicao', 'tipo_instituicao', 'vinculo', 'titulacao',
        'curso_formacao', 'area_id', 'subarea_id', 'tempo_orientacao',
        'vezes_fetec', 'ex_aluno_fetec', 'cep', 'logradouro', 'numero', 'complemento',
        'bairro', 'estado_id', 'cidade_id', 'estado_nome', 'cidade_nome', 'pais',
    ];

    public function __construct(private readonly SubareaService $subareas) {}

    /**
     * Cria o usuário (role orientador) e o perfil numa única transação.
     * A senha é hasheada pelo cast 'hashed' do model User.
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $data = $this->resolverSubarea($data);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => Role::Orientador,
                'is_active' => true,
            ]);

            $user->orientadorProfile()->create(Arr::only($data, self::PROFILE_FIELDS));

            return $user->load(
                'orientadorProfile.estado', 'orientadorProfile.cidade',
                'orientadorProfile.area', 'orientadorProfile.subarea',
            );
        });
    }

    /**
     * Atualiza dados do usuário (name/email) e do perfil do orientador.
     */
    public function updatePerfil(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $data = $this->resolverSubarea($data);

            $userData = Arr::only($data, ['name', 'email']);
            if (! empty($userData)) {
                $user->fill($userData)->save();
            }

            $profileData = Arr::only($data, self::PROFILE_FIELDS);
            if (! empty($profileData)) {
                $user->orientadorProfile()->update($profileData);
            }

            return $user->fresh([
                'orientadorProfile.estado', 'orientadorProfile.cidade',
                'orientadorProfile.area', 'orientadorProfile.subarea',
            ]);
        });
    }

    /**
     * Se veio uma subárea NOVA por texto (subarea_nome) sem id, cria/reaproveita a
     * subárea global na área escolhida e injeta o subarea_id resultante.
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
