<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Edicao extends Model
{
    protected $table = 'edicoes';

    protected $fillable = ['nome', 'ano', 'inscricoes_abertas', 'inicio_em', 'fim_em'];

    protected function casts(): array
    {
        return [
            'inscricoes_abertas' => 'boolean',
            'inicio_em' => 'date',
            'fim_em' => 'date',
        ];
    }
}
