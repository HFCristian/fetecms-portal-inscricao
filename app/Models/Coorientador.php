<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coorientador extends Model
{
    /** @use HasFactory<\Database\Factories\CoorientadorFactory> */
    use HasFactory;

    protected $table = 'coorientadores';

    protected $fillable = [
        'projeto_id', 'nome', 'email', 'cpf', 'telefone', 'data_nascimento', 'genero', 'camiseta',
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }
}
