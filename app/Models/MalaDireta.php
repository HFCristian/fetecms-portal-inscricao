<?php

namespace App\Models;

use App\Enums\StatusDestinatario;
use App\Enums\StatusMala;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um disparo de mala direta: o comunicado (assunto/corpo), o critério que
 * formou a lista e o snapshot dos destinatários com o resultado de cada envio.
 */
class MalaDireta extends Model
{
    protected $table = 'malas_diretas';

    protected $fillable = [
        'nome', 'justificativa', 'solicitante', 'assunto', 'corpo',
        'publicos', 'emails_personalizados', 'status',
        'user_id', 'autor_nome', 'autor_email',
        'enviado_em', 'concluido_em',
    ];

    protected function casts(): array
    {
        return [
            'publicos' => 'array',
            'status' => StatusMala::class,
            'enviado_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function destinatarios(): HasMany
    {
        return $this->hasMany(MalaDiretaDestinatario::class);
    }

    /**
     * Contagem por situação (base da barra de progresso e do relatório).
     *
     * @return array{total: int, pendente: int, enviado: int, falha: int, invalido: int, processados: int}
     */
    public function totais(): array
    {
        $contagem = $this->destinatarios()
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $totais = ['total' => 0];
        foreach (StatusDestinatario::cases() as $status) {
            $totais[$status->value] = (int) ($contagem[$status->value] ?? 0);
            $totais['total'] += $totais[$status->value];
        }
        // Inválido nunca entra na fila: já nasce processado.
        $totais['processados'] = $totais['total'] - $totais[StatusDestinatario::Pendente->value];

        return $totais;
    }
}
