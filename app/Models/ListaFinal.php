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
 *
 * `demo` separa a lista de treinamento (Sprint 88) da oficial: as duas convivem
 * com uma vigente cada, e a demo só é enxergada por quem ligou o modo de teste.
 * Todo o resto do portal continua chamando `vigente()` sem argumento e vendo
 * apenas a oficial.
 */
class ListaFinal extends Model
{
    protected $table = 'listas_finais';

    protected $fillable = ['edicao_id', 'nome', 'vigente', 'demo', 'versao', 'cotas', 'gerada_por'];

    protected function casts(): array
    {
        return [
            'vigente' => 'boolean',
            'demo' => 'boolean',
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

    /**
     * A lista vigente da edição em escopo (null se ainda não houver oficial).
     *
     * `$demo` escolhe qual das duas trilhas responder: a oficial (padrão) ou a
     * de treinamento. Elas nunca se misturam — publicar uma não encerra a outra.
     */
    public static function vigente(?Edicao $edicao = null, bool $demo = false): ?self
    {
        $edicao ??= Edicao::atual();

        return $edicao === null
            ? null
            : static::where('edicao_id', $edicao->id)
                ->where('vigente', true)
                ->where('demo', $demo)
                ->latest('id')
                ->first();
    }
}
