<?php

namespace Database\Factories;

use App\Models\OrientadorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrientadorProfile>
 */
class OrientadorProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'cpf' => fake()->numerify('###########'),
            'telefone' => fake()->numerify('67#########'),
            'data_nascimento' => fake()->dateTimeBetween('-60 years', '-22 years')->format('Y-m-d'),
            'genero' => fake()->randomElement(['F', 'M', 'NB', 'O', 'P']),
            'camiseta' => fake()->randomElement(['PP', 'P', 'M', 'G', 'GG', 'XG']),
            'pcd' => false,
            // instituicao_id (FK) fica nulo por padrão; testes que precisam definem.
            'titulacao' => fake()->randomElement(['Graduação', 'Mestrado', 'Doutorado']),
            // Endereço por FK (estado_id/cidade_id) fica nulo por padrão para a factory
            // não depender do catálogo semeado; testes que precisam definem explicitamente.
            'pais' => 'BR',
        ];
    }
}
