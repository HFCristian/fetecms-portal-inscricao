<?php

namespace Database\Factories;

use App\Models\Projeto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Aluno>
 */
class AlunoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'projeto_id' => Projeto::factory(),
            'nome' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'cpf' => fake()->unique()->numerify('###########'),
            'telefone' => fake()->numerify('67#########'),
            'data_nascimento' => fake()->dateTimeBetween('-18 years', '-12 years')->format('Y-m-d'),
            'genero' => fake()->randomElement(['F', 'M', 'NB']),
            'camiseta' => fake()->randomElement(['PP', 'P', 'M', 'G', 'GG']),
            'modalidade' => fake()->randomElement(['fundamental', 'medio', 'tecnico']),
        ];
    }
}
