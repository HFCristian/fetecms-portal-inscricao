<?php

namespace App\Models;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Projeto extends Model
{
    /** @use HasFactory<\Database\Factories\ProjetoFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'projetos';

    /**
     * Nota de segurança: `user_id` consta no fillable só por conveniência de
     * factory/seed. Nos fluxos reais ele é SEMPRE definido a partir do usuário
     * autenticado (via $user->projetos()->create(...)), nunca da entrada do
     * request — o FormRequest não valida/aceita user_id.
     */
    protected $fillable = [
        'user_id', 'edicao_id', 'titulo', 'categoria', 'instituicao_id', 'area_id',
        'subarea_id', 'resumo', 'link_video', 'palavras_chave', 'pais', 'estado_id',
        'cidade_id', 'continuacao', 'tempo_pesquisa_meses', 'feira_afiliada',
        'numero_credencial', 'agenda_2030', 'categoria_agenda_2030', 'email_comunicacao',
        'status', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'categoria' => Categoria::class,
            'status' => ProjetoStatus::class,
            'palavras_chave' => 'array',
            'continuacao' => 'boolean',
            'feira_afiliada' => 'boolean',
            'agenda_2030' => 'boolean',
            'tempo_pesquisa_meses' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function subarea(): BelongsTo
    {
        return $this->belongsTo(Subarea::class);
    }

    public function instituicao(): BelongsTo
    {
        return $this->belongsTo(Instituicao::class);
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class);
    }

    public function cidade(): BelongsTo
    {
        return $this->belongsTo(Cidade::class);
    }
}
