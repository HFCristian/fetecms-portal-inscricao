<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Uma credencial da edição: a vaga de premiação que a feira tem para dar —
 * uma indicação a outra feira, uma bolsa, um prêmio de parceiro.
 *
 * `vagas` nulo significa **sem teto**: nem todo órgão fecha o número antes do
 * evento, e travar em zero seria pior do que não travar.
 */
class Credencial extends Model
{
    protected $table = 'credenciais';

    protected $fillable = ['edicao_id', 'nome', 'orgao', 'descricao', 'vagas', 'ativa', 'ordem'];

    protected function casts(): array
    {
        return [
            'vagas' => 'integer',
            'ativa' => 'boolean',
            'ordem' => 'integer',
        ];
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    public function projetos(): BelongsToMany
    {
        return $this->belongsToMany(Projeto::class, 'credencial_projeto')
            ->withPivot(['atribuida_por', 'atribuida_em', 'observacao'])
            ->withTimestamps();
    }

    /** Ainda cabe mais um projeto nesta credencial? */
    public function temVaga(): bool
    {
        return $this->vagas === null || $this->projetos()->count() < $this->vagas;
    }
}
