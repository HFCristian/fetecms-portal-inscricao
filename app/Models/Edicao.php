<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Edicao extends Model
{
    protected $table = 'edicoes';

    protected $fillable = ['nome', 'ano', 'inscricoes_abertas', 'inicio_em', 'fim_em', 'avaliacao_liberada_em'];

    protected function casts(): array
    {
        return [
            'inscricoes_abertas' => 'boolean',
            'inicio_em' => 'date',
            'fim_em' => 'date',
            'avaliacao_liberada_em' => 'datetime',
        ];
    }

    /** Edição atual (a que está com inscrições abertas). */
    public static function atual(): ?self
    {
        return static::where('inscricoes_abertas', true)->latest('ano')->first();
    }

    /** A avaliação online já foi liberada (data definida e já alcançada)? */
    public function avaliacaoLiberada(): bool
    {
        return $this->avaliacao_liberada_em !== null && now()->greaterThanOrEqualTo($this->avaliacao_liberada_em);
    }
}
