<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quem o intérprete atende: o projeto inteiro (`pessoa_tipo` nulo) ou um
 * participante dele. Ver a migration das tabelas de intérpretes.
 */
class InterpreteVinculo extends Model
{
    public const ORIGEM_CADASTRO = 'cadastro';

    public const ORIGEM_MAPA = 'mapa';

    public const ORIGEM_PEDIDO = 'pedido';

    public const PESSOA_ALUNO = 'aluno';

    public const PESSOA_ORIENTADOR = 'orientador';

    public const PESSOA_COORIENTADOR = 'coorientador';

    public const PESSOAS = [self::PESSOA_ALUNO, self::PESSOA_ORIENTADOR, self::PESSOA_COORIENTADOR];

    protected $fillable = ['interprete_id', 'projeto_id', 'pessoa_tipo', 'pessoa_id', 'origem'];

    protected function casts(): array
    {
        return ['pessoa_id' => 'integer'];
    }

    public function interprete(): BelongsTo
    {
        return $this->belongsTo(Interprete::class);
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }

    /** Vínculo ao projeto inteiro (e não a um participante). */
    public function doProjeto(): bool
    {
        return $this->pessoa_tipo === null;
    }
}
