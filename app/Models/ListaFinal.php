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
 * `rascunho` (Sprint 128) é a lista **gerada mas ainda não publicada**: o
 * recorte que o admin está revendo antes de baixar o TXT. Ela não é vigente e
 * não define finalista nenhum; publicar é que a torna oficial.
 *
 * `demo` separa a lista de treinamento (Sprint 88) da oficial: as duas convivem
 * com uma vigente cada, e a demo só é enxergada por quem ligou o modo de teste.
 * Todo o resto do portal continua chamando `vigente()` sem argumento e vendo
 * apenas a oficial.
 *
 * `tipo` (Sprint 164) separa a lista **preliminar** — várias convivem, nenhuma
 * define finalista — da **final**, que tem uma só **ativa** (`vigente`) por
 * edição e vale para toda a etapa presencial. A final pode nascer da
 * classificação ou da união de preliminares (`origens`). `rascunho` continua
 * sendo o "ainda não gerada": o admin edita à vontade, e gerar é o que fecha.
 */
class ListaFinal extends Model
{
    protected $table = 'listas_finais';

    protected $fillable = [
        'edicao_id', 'nome', 'tipo', 'vigente', 'rascunho', 'demo', 'versao', 'cotas', 'origens', 'gerada_em', 'gerada_por',
        'codigos_congelados_em', 'codigos_enviados_em', 'codigos_mala_id',
    ];

    /** O padrão do banco também na memória: uma lista recém-criada já sabe o tipo. */
    protected $attributes = ['tipo' => self::TIPO_FINAL];

    protected function casts(): array
    {
        return [
            'vigente' => 'boolean',
            'rascunho' => 'boolean',
            'demo' => 'boolean',
            'versao' => 'integer',
            'cotas' => 'array',
            'origens' => 'array',
            'gerada_em' => 'datetime',
            'codigos_congelados_em' => 'datetime',
            'codigos_enviados_em' => 'datetime',
        ];
    }

    public const TIPO_PRELIMINAR = 'preliminar';

    public const TIPO_FINAL = 'final';

    public function ehFinal(): bool
    {
        return $this->tipo === self::TIPO_FINAL;
    }

    public function ehPreliminar(): bool
    {
        return $this->tipo === self::TIPO_PRELIMINAR;
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
            ->withPivot('manual', 'codigo')
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
                ->where('tipo', self::TIPO_FINAL)
                ->where('vigente', true)
                ->where('demo', $demo)
                ->latest('id')
                ->first();
    }
}
