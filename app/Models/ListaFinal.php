<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Uma lista final **oficial**: o recorte de projetos que vai para a
 * programação da feira, com as cotas que a geraram e a versão corrente.
 *
 * A lista **vigente** da edição é a que define quem é finalista — e é dela que
 * o credenciamento tira as pessoas que vão passar pelo balcão.
 */
class ListaFinal extends Model
{
    protected $table = 'listas_finais';

    protected $fillable = ['edicao_id', 'nome', 'vigente', 'versao', 'cotas', 'gerada_por'];

    protected function casts(): array
    {
        return [
            'vigente' => 'boolean',
            'versao' => 'integer',
            'cotas' => 'array',
        ];
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerada_por');
    }

    public function projetos(): BelongsToMany
    {
        return $this->belongsToMany(Projeto::class, 'lista_final_projetos', 'lista_final_id', 'projeto_id')
            ->withPivot('manual')
            ->withTimestamps();
    }

    /** A lista vigente da edição em escopo (null se ainda não houver oficial). */
    public static function vigente(?Edicao $edicao = null): ?self
    {
        $edicao ??= Edicao::atual();

        return $edicao === null
            ? null
            : static::where('edicao_id', $edicao->id)->where('vigente', true)->latest('id')->first();
    }
}
