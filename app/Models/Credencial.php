<?php

namespace App\Models;

use App\Enums\TipoCredencial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Uma linha de *Credenciais e Prêmios*: o que a feira tem para dar a um
 * projeto finalista — uma indicação a outra feira, uma bolsa, o prêmio de um
 * parceiro, o destaque anunciado no palco.
 *
 * O `tipo` separa a **credencial**, que tem objeto a separar no balcão do
 * cerimonial, do **prêmio**, que só se anuncia. As duas fazem o projeto ser
 * premiado; só a primeira entra no card de credenciais a separar.
 *
 * `vagas` nulo significa **sem teto**: nem todo órgão fecha o número antes do
 * evento, e travar em zero seria pior do que não travar.
 */
class Credencial extends Model
{
    protected $table = 'credenciais';

    protected $fillable = ['edicao_id', 'tipo', 'nome', 'orgao', 'descricao', 'vagas', 'ativa', 'ordem'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCredencial::class,
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

    /** Só o que vira objeto na mesa do cerimonial. */
    public function ehCredencial(): bool
    {
        return $this->tipo === TipoCredencial::Credencial;
    }

    /** Ainda cabe mais um projeto nesta credencial? */
    public function temVaga(): bool
    {
        return $this->vagas === null || $this->projetos()->count() < $this->vagas;
    }
}
