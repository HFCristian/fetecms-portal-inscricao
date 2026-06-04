<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrientadorProfile extends Model
{
    /** @use HasFactory<\Database\Factories\OrientadorProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'cpf', 'telefone', 'data_nascimento', 'genero', 'genero_outro', 'etnia',
        'camiseta', 'pcd', 'instituicao', 'tipo_instituicao', 'vinculo', 'titulacao',
        'curso_formacao', 'area_conhecimento', 'subarea', 'tempo_orientacao',
        'vezes_fetec', 'ex_aluno_fetec', 'cep', 'logradouro', 'numero', 'complemento',
        'bairro', 'cidade', 'estado', 'pais',
    ];

    protected function casts(): array
    {
        return [
            'data_nascimento' => 'date',
            'pcd' => 'boolean',
            'ex_aluno_fetec' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
