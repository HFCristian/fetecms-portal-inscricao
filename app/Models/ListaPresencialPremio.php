<?php

namespace App\Models;

use App\Enums\TipoCredencial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Uma credencial ou um prêmio dentro de uma lista presencial (Sprint 253): a
 * cópia do que estava em Credenciais e Prêmios quando a lista foi montada,
 * com os projetos que a receberam — editável sem mexer no cadastro original.
 */
class ListaPresencialPremio extends Model
{
    protected $table = 'lista_presencial_premios';

    protected $fillable = ['lista_presencial_id', 'credencial_id', 'tipo', 'nome', 'orgao', 'ordem'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCredencial::class,
            'ordem' => 'integer',
        ];
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ListaPresencial::class, 'lista_presencial_id');
    }

    public function projetos(): BelongsToMany
    {
        return $this->belongsToMany(Projeto::class, 'lista_presencial_premio_projetos', 'premio_id', 'projeto_id')
            ->withPivot('manual')
            ->withTimestamps();
    }

    /** O título da seção: "Nome — órgão". */
    public function titulo(): string
    {
        return $this->nome.($this->orgao ? ' — '.$this->orgao : '');
    }
}
