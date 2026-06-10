<?php

namespace App\Models;

use Database\Factories\CoorientadorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coorientador extends Model
{
    /** @use HasFactory<CoorientadorFactory> */
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
