<?php

namespace App\Models;

use App\Enums\MeioTransporte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma sessão do localizador do comitê: quem ligou, com quantas pessoas, de onde
 * saiu, para onde vai e até quando fica ligado.
 *
 * A **última posição** fica aqui mesmo (é o que o mapa lê); o trajeto vivo está
 * em `pontos` e é apagado quando a sessão termina.
 */
class LocalizacaoComite extends Model
{
    protected $table = 'localizacoes_comite';

    protected $fillable = [
        'user_id', 'edicao_id', 'pessoas', 'acompanhantes', 'transporte',
        'origem_nome', 'origem_lat', 'origem_lng',
        'destino_nome', 'destino_lat', 'destino_lng',
        'expira_em', 'encerrado_em',
        'latitude', 'longitude', 'precisao_m', 'distancia_m', 'duracao_s', 'posicao_em',
    ];

    protected function casts(): array
    {
        return [
            'pessoas' => 'integer',
            'acompanhantes' => 'array',
            'transporte' => MeioTransporte::class,
            'origem_lat' => 'float',
            'origem_lng' => 'float',
            'destino_lat' => 'float',
            'destino_lng' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'precisao_m' => 'integer',
            'distancia_m' => 'integer',
            'duracao_s' => 'integer',
            'expira_em' => 'datetime',
            'encerrado_em' => 'datetime',
            'posicao_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pontos(): HasMany
    {
        return $this->hasMany(LocalizacaoComitePonto::class, 'localizacao_comite_id');
    }

    /** O localizador ainda está ligado? (não encerrado à mão e dentro do prazo) */
    public function ativa(): bool
    {
        return $this->encerrado_em === null && $this->expira_em->isFuture();
    }

    /**
     * Sessões ligadas agora. Quem venceu o prazo sai do mapa sozinho — o
     * localizador tem hora para desligar.
     */
    public function scopeAtivas($query)
    {
        return $query->whereNull('encerrado_em')->where('expira_em', '>', now());
    }
}
