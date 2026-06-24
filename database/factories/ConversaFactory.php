<?php

namespace Database\Factories;

use App\Enums\StatusConversa;
use App\Models\Conversa;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversa>
 */
class ConversaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => StatusConversa::NaoVisualizada,
            'ultima_mensagem_em' => now(),
        ];
    }

    public function status(StatusConversa $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
