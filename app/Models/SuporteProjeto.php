<?php

namespace App\Models;

use App\Enums\StatusSuporte;
use App\Enums\TipoSuporte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de suporte de um projeto finalista (Sprint 162): acompanhante,
 * intérprete de Libras ou de outra língua. O acompanhante aprovado é uma
 * pessoa do evento, com crachá próprio (papel `S` no código).
 */
class SuporteProjeto extends Model
{
    protected $table = 'suportes_projeto';

    protected $fillable = [
        'projeto_id', 'tipo', 'idioma', 'aluno_id', 'acompanhante_nome', 'acompanhante_documento',
        'acompanhante_vinculo', 'observacao', 'status', 'motivo', 'solicitado_por', 'decidido_por', 'decidido_em',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoSuporte::class,
            'status' => StatusSuporte::class,
            'decidido_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    public function aprovado(): bool
    {
        return $this->status === StatusSuporte::Aprovado;
    }

    /**
     * O pedido numa frase, para o selo das telas de credenciamento e avaliação:
     * "Acompanhante: Maria Souza (mãe) — de Ana", "Intérprete de outra língua
     * (Espanhol) — para Ana".
     */
    public function resumo(): string
    {
        $aluno = $this->aluno?->nome;

        return match ($this->tipo) {
            TipoSuporte::Acompanhante => 'Acompanhante: '.($this->acompanhante_nome ?? '—')
                .($this->acompanhante_vinculo ? " ({$this->acompanhante_vinculo})" : '')
                .($aluno ? " — de {$aluno}" : ''),
            TipoSuporte::InterpreteLingua => 'Intérprete de outra língua'
                .($this->idioma ? " ({$this->idioma})" : '')
                .($aluno ? " — para {$aluno}" : ''),
            default => $this->tipo->label().($aluno ? " — para {$aluno}" : ''),
        };
    }
}
