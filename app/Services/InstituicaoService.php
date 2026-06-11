<?php

namespace App\Services;

use App\Models\Instituicao;

class InstituicaoService
{
    /**
     * Encontra (sem diferenciar maiúsc/minúsc) ou cria a instituição global pelo par
     * (nome, cidade). O MESMO nome em CIDADES diferentes vira instituições distintas
     * (ex.: escola estadual de mesmo nome em MS e no RS); mesmo nome na mesma cidade
     * (ou ambos sem cidade) reaproveita. Duplicatas podem ser limpas depois pelo admin.
     */
    public function firstOrCreateGlobal(string $nome, array $extras = []): Instituicao
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome));
        $cidadeId = $extras['cidade_id'] ?? null;

        $query = Instituicao::whereRaw('LOWER(nome) = LOWER(?)', [$nome]);
        $cidadeId === null
            ? $query->whereNull('cidade_id')
            : $query->where('cidade_id', $cidadeId);

        return $query->first() ?? Instituicao::create(array_merge($extras, ['nome' => $nome]));
    }
}
