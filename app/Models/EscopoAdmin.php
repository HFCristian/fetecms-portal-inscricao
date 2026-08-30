<?php

namespace App\Models;

use App\Enums\AbaAdmin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Perfil de acesso do admin: um nome e as abas do menu que ele abre
 * (Parametrização → Escopos de admin).
 *
 * O escopo é atribuído a cada admin **por edição** — a mesma pessoa pode cuidar
 * da comunicação num ano e do credenciamento no outro.
 */
class EscopoAdmin extends Model
{
    protected $table = 'escopos_admin';

    protected $fillable = ['nome', 'abas'];

    protected function casts(): array
    {
        return ['abas' => 'array'];
    }

    /** Este escopo abre esta aba? */
    public function permite(AbaAdmin $aba): bool
    {
        return in_array($aba->value, $this->abas ?? [], true);
    }

    /** Abre todas as abas que existem? (o escopo "Acesso total") */
    public function ehTotal(): bool
    {
        return count(array_intersect(AbaAdmin::valores(), $this->abas ?? [])) === count(AbaAdmin::valores());
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'admin_escopos', 'escopo_admin_id', 'user_id')
            ->withPivot('edicao_id')
            ->withTimestamps();
    }
}
