<?php

namespace App\Models;

use App\Enums\TipoPessoaCredenciamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma pessoa do projeto no balcão do credenciamento: se apareceu e o que
 * aconteceu com o kit dela.
 *
 * A retirada mora aqui, e não no credenciamento, porque o kit é **por pessoa**
 * e raramente sai todo de uma vez: quem vem ao balcão leva o próprio e o de
 * alguns colegas, e o restante é retirado depois — por outra pessoa, em outro
 * horário. Cada linha guarda o seu responsável e o seu relógio.
 *
 * Ausente não é o mesmo que "não conferido": é decisão registrada, e é ela que
 * dispensa os documentos daquela pessoa sem travar o credenciamento do projeto.
 */
class CredenciamentoPessoa extends Model
{
    protected $table = 'credenciamento_pessoas';

    protected $fillable = [
        'credenciamento_id', 'pessoa_tipo', 'pessoa_id', 'pessoa_nome', 'presente',
        'kit_retirado_em', 'kit_retirado_por_tipo', 'kit_retirado_por_id',
        'kit_retirado_por_nome', 'kit_registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'pessoa_tipo' => TipoPessoaCredenciamento::class,
            'presente' => 'boolean',
            'kit_retirado_em' => 'datetime',
        ];
    }

    public function credenciamento(): BelongsTo
    {
        return $this->belongsTo(Credenciamento::class);
    }

    /** O admin que registrou a retirada do kit (não quem o levou). */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kit_registrado_por');
    }

    public function kitRetirado(): bool
    {
        return $this->kit_retirado_em !== null;
    }
}
