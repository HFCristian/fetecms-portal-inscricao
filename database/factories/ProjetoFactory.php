<?php

namespace Database\Factories;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Projeto>
 */
class ProjetoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'titulo' => fake()->sentence(4),
            'categoria' => fake()->randomElement(Categoria::cases()),
            'resumo' => fake()->paragraph(),
            'palavras_chave' => fake()->randomElements(
                ['Robótica', 'Sustentabilidade', 'Energia Solar', 'Biotecnologia', 'IA'],
                3
            ),
            'pais' => 'BR',
            'status' => ProjetoStatus::Rascunho,
        ];
    }

    public function submetido(): static
    {
        return $this->state(fn () => [
            'status' => ProjetoStatus::Submetido,
            'submitted_at' => now(),
        ]);
    }
}
