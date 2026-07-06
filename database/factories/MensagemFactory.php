<?php

namespace Database\Factories;

use App\Models\Conversa;
use App\Models\Mensagem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mensagem>
 */
class MensagemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversa_id' => Conversa::factory(),
            'autor' => Mensagem::AUTOR_USUARIO,
            'autor_user_id' => null,
            'corpo' => fake()->sentence(),
        ];
    }

    public function doSuporte(): static
    {
        return $this->state(fn (array $attributes) => ['autor' => Mensagem::AUTOR_SUPORTE]);
    }
}
