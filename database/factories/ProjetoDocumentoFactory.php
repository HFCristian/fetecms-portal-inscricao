<?php

namespace Database\Factories;

use App\Enums\TipoDocumento;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\ProjetoDocumento>
 */
class ProjetoDocumentoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'projeto_id' => Projeto::factory(),
            'tipo' => TipoDocumento::PlanoPesquisa,
            'disk' => 'local',
            'path' => 'projetos/teste/'.fake()->uuid().'.pdf',
            'nome_original' => 'plano-de-pesquisa.pdf',
            'mime' => 'application/pdf',
            'tamanho_bytes' => 1024,
        ];
    }
}
