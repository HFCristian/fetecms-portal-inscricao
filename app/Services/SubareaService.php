<?php

namespace App\Services;

use App\Models\Subarea;
use Illuminate\Database\QueryException;

class SubareaService
{
    /**
     * Encontra (sem diferenciar maiúsc/minúsc) ou cria a subárea DENTRO da área,
     * deduplicando por nome normalizado (trim + espaços colapsados). A subárea é
     * global: passa a aparecer para todos (orientadores, avaliadores e projetos).
     * O índice único (area_id, nome) protege contra corrida em criações simultâneas.
     */
    public function firstOrCreateNaArea(int $areaId, string $nome): Subarea
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        $existente = $this->buscar($areaId, $nome);
        if ($existente) {
            return $existente;
        }

        try {
            return Subarea::create(['area_id' => $areaId, 'nome' => $nome]);
        } catch (QueryException $e) {
            // Corrida: outra requisição criou a mesma subárea — relê e devolve.
            return $this->buscar($areaId, $nome) ?? throw $e;
        }
    }

    private function buscar(int $areaId, string $nome): ?Subarea
    {
        return Subarea::where('area_id', $areaId)
            ->whereRaw('LOWER(nome) = LOWER(?)', [$nome])
            ->first();
    }
}
