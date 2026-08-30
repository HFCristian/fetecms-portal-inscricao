<?php

namespace App\Models;

use App\Enums\StatusDistribuicao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma rodada de "Distribuir" ou "Redistribuir" avaliações.
 *
 * Existe para a tela poder desenhar a barra de progresso: o trabalho roda na
 * fila e vai atualizando `processados` aqui; o front consulta até o status
 * ficar finalizado.
 */
class Distribuicao extends Model
{
    protected $table = 'distribuicoes';

    public const TIPO_DISTRIBUIR = 'distribuir';

    public const TIPO_REDISTRIBUIR = 'redistribuir';

    protected $fillable = [
        'edicao_id', 'tipo', 'status', 'total', 'processados',
        'etapa', 'relatorio', 'erro', 'iniciada_por', 'concluida_em',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusDistribuicao::class,
            'relatorio' => 'array',
            'concluida_em' => 'datetime',
        ];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciada_por');
    }

    /** Quanto da barra já foi, de 0 a 100. Sem total conhecido, ainda é 0. */
    public function percentual(): int
    {
        if ($this->status === StatusDistribuicao::Concluida) {
            return 100;
        }

        return $this->total > 0
            ? min(100, (int) floor($this->processados / $this->total * 100))
            : 0;
    }

    /** @return array<string, mixed> */
    public function paraApi(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'finalizada' => $this->status->finalizada(),
            'total' => $this->total,
            'processados' => $this->processados,
            'percentual' => $this->percentual(),
            'etapa' => $this->etapa,
            'relatorio' => $this->relatorio,
            'erro' => $this->erro,
            'concluida_em' => $this->concluida_em?->toIso8601String(),
        ];
    }
}
