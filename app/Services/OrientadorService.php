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
        'curso_formacao', 'area_conhecimento', 'subarea', 'tempo_orientacao',
        'vezes_fetec', 'ex_aluno_fetec', 'cep', 'logradouro', 'numero', 'complemento',
        'bairro', 'cidade', 'estado', 'pais',
    ];

    /**
     * Cria o usuário (role orientador) e o perfil numa única transação.
     * A senha é hasheada pelo cast 'hashed' do model User.
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => Role::Orientador,
                'is_active' => true,
            ]);

            $user->orientadorProfile()->create(Arr::only($data, self::PROFILE_FIELDS));

            return $user->load('orientadorProfile');
        });
    }

    /**
     * Atualiza dados do usuário (name/email) e do perfil do orientador.
     */
    public function updatePerfil(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $userData = Arr::only($data, ['name', 'email']);
            if (! empty($userData)) {
                $user->fill($userData)->save();
            }

            $profileData = Arr::only($data, self::PROFILE_FIELDS);
            if (! empty($profileData)) {
                $user->orientadorProfile()->update($profileData);
            }

            return $user->fresh('orientadorProfile');
        });
    }
}
