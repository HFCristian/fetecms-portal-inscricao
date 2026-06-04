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
            'data_nascimento' => fake()->dateTimeBetween('-60 years', '-20 years')->format('Y-m-d'),
            'genero' => fake()->randomElement(['F', 'M', 'NB', 'O', 'P']),
            'camiseta' => fake()->randomElement(['PP', 'P', 'M', 'G', 'GG', 'XG']),
            'pcd' => false,
            'instituicao' => fake()->randomElement(['UFMS', 'UEMS', 'IFMS', 'UCDB']),
            'titulacao' => fake()->randomElement(['Graduação', 'Mestrado', 'Doutorado']),
            'cidade' => 'Campo Grande',
            'estado' => 'MS',
            'pais' => 'BR',
        ];
    }
}
