<?php

namespace Database\Factories;

use App\Models\Coorientador;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coorientador>
 */
class CoorientadorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'projeto_id' => Projeto::factory(),
            'nome' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'cpf' => fake()->unique()->numerify('###########'),
            'telefone' => fake()->numerify('67#########'),
            'data_nascimento' => fake()->dateTimeBetween('-60 years', '-25 years')->format('Y-m-d'),
            'genero' => fake()->randomElement(['F', 'M', 'NB']),
            'camiseta' => fake()->randomElement(['P', 'M', 'G', 'GG']),
        ];
    }
}
