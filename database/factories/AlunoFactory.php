<?php

namespace Database\Factories;

use App\Models\Aluno;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Aluno>
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
            // Modalidade e série saem sempre em par coerente, com os MESMOS
            // códigos que o formulário do aluno grava — é o que o painel usa
            // para montar os cards por classe escolar.
            ...self::classeAleatoria(),
        ];
    }

    /** @return array{modalidade: string, ano_escolar: string} */
    private static function classeAleatoria(): array
    {
        $porModalidade = [
            'fundamental_i' => ['3_ef', '4_ef', '5_ef'],
            'fundamental_ii' => ['6_ef', '7_ef', '8_ef', '9_ef'],
            'medio' => ['1_em', '2_em', '3_em'],
            'tecnico_integrado' => ['1_em', '2_em', '3_em', '4_em'],
        ];

        $modalidade = fake()->randomElement(array_keys($porModalidade));

        return [
            'modalidade' => $modalidade,
            'ano_escolar' => fake()->randomElement($porModalidade[$modalidade]),
        ];
    }

    /** Aluno de uma classe/série específica (para os testes dos cards do painel). */
    public function classe(string $modalidade, ?string $anoEscolar = null): static
    {
        return $this->state(fn () => array_filter([
            'modalidade' => $modalidade,
            'ano_escolar' => $anoEscolar,
        ]));
    }
}
