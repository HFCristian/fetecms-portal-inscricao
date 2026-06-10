<?php

namespace App\Services;

use App\Models\Coorientador;
use App\Models\Projeto;

class CoorientadorService
{
    /**
     * Cria ou substitui o (único) coorientador do projeto.
     * A unicidade é garantida pelo índice unique em coorientadores.projeto_id.
     */
    public function upsert(Projeto $projeto, array $data): Coorientador
    {
        return $projeto->coorientador()->updateOrCreate(
            ['projeto_id' => $projeto->id],
            $data,
        );
    }
}
