<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Edicao extends Model
{
    protected $table = 'edicoes';

    protected $fillable = ['nome', 'ano', 'inscricoes_abertas', 'inicio_em', 'fim_em', 'avaliacao_liberada_em', 'submissoes_ate'];

    protected function casts(): array
    {
        return [
            'inscricoes_abertas' => 'boolean',
            'inicio_em' => 'date',
            'fim_em' => 'date',
            'avaliacao_liberada_em' => 'datetime',
            'submissoes_ate' => 'datetime',
        ];
    }

    /** Edição atual (a que está com inscrições abertas). */
    public static function atual(): ?self
    {
        return static::where('inscricoes_abertas', true)->latest('ano')->first();
    }

    /** O prazo de submissão já passou? Sem prazo definido, as inscrições ficam abertas. */
    public function inscricoesEncerradas(): bool
    {
        return $this->submissoes_ate !== null && now()->greaterThan($this->submissoes_ate);
    }

    /** A avaliação online já foi liberada (data definida e já alcançada)? */
    public function avaliacaoLiberada(): bool
    {
        return $this->avaliacao_liberada_em !== null && now()->greaterThanOrEqualTo($this->avaliacao_liberada_em);
    }
}
