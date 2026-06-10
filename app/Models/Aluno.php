<?php

namespace App\Models;

use Database\Factories\AlunoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Aluno extends Model
{
    /** @use HasFactory<AlunoFactory> */
    use HasFactory;

    protected $table = 'alunos';

    protected $fillable = [
        'projeto_id', 'nome', 'email', 'cpf', 'telefone', 'data_nascimento', 'genero',
        'etnia', 'camiseta', 'instituicao_id', 'modalidade', 'ano_escolar', 'periodo',
        'graduacao_pretendida', 'bolsista', 'clube_ciencias', 'autorizacao_menor',
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'bolsista' => 'boolean',
            'clube_ciencias' => 'boolean',
            'autorizacao_menor' => 'boolean',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function instituicao(): BelongsTo
    {
        return $this->belongsTo(Instituicao::class);
    }
}
