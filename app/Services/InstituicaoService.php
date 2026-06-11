<?php

namespace App\Services;

use App\Models\Instituicao;

class InstituicaoService
{
    /**
     * Encontra (sem diferenciar maiúsc/minúsc) ou cria a instituição global pelo nome.
     * A instituição passa a aparecer na busca para todos (orientadores e projetos).
     * Duplicatas/ruído podem ser limpos depois pelo admin.
     */
    public function firstOrCreateGlobal(string $nome, array $extras = []): Instituicao
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        $existente = Instituicao::whereRaw('LOWER(nome) = LOWER(?)', [$nome])->first();

        return $existente ?? Instituicao::create(array_merge($extras, ['nome' => $nome]));
    }
}
