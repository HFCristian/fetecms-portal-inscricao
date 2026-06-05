<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\AvaliadorProfile>
 */
class AvaliadorProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->avaliador(),
            'cpf' => fake()->unique()->numerify('###########'),
            'titulacao' => fake()->randomElement(['Mestrado', 'Doutorado', 'Especialização']),
        ];
    }
}
