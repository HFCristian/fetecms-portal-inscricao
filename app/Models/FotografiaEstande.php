<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fotografia do estande de um projeto finalista (Sprint 247) — e a reserva
 * de quem está registrando agora.
 *
 * Sem `caminho` a linha é só a reserva ("em registro"); com ele, a foto. A
 * reserva vale por {@see self::MINUTOS_RESERVA} minutos: quem confirmou o
 * estande e largou o celular não segura o estande para sempre.
 */
class FotografiaEstande extends Model
{
    /** Quanto tempo a reserva de "em registro" vale sem a foto chegar. */
    public const MINUTOS_RESERVA = 15;

    /** O maior arquivo aceito, em KB (100 MB). */
    public const MAX_KB = 102400;

    protected $table = 'fotografias_estande';

    protected $fillable = [
        'edicao_id', 'projeto_id', 'caminho', 'nome_original', 'mime', 'tamanho',
        'registrado_por', 'registrado_em', 'em_registro_por', 'em_registro_desde',
    ];

    protected function casts(): array
    {
        return [
            'tamanho' => 'integer',
            'registrado_em' => 'datetime',
            'em_registro_desde' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function fotografo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'em_registro_por');
    }

    public function temFoto(): bool
    {
        return $this->caminho !== null;
    }

    /** Alguém confirmou o estande há menos de 15 minutos e ainda não salvou. */
    public function emRegistro(): bool
    {
        return $this->em_registro_por !== null
            && $this->em_registro_desde !== null
            && $this->em_registro_desde->greaterThan(now()->subMinutes(self::MINUTOS_RESERVA));
    }

    /** A reserva ativa é de outra pessoa? */
    public function reservadaPorOutro(User $user): bool
    {
        return $this->emRegistro() && $this->em_registro_por !== $user->id;
    }
}
