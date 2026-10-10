<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Para quem um arquivo enviado antes vale (Sprint 183): uma pessoa do projeto,
 * pelo papel e pelo id — estudante (`alunos.id`), orientador (`users.id`) ou
 * coorientador (`coorientadores.id`), como no balcão.
 */
class ProjetoDocumentoPessoa extends Model
{
    protected $table = 'projeto_documento_pessoas';

    protected $fillable = ['projeto_documento_id', 'pessoa_tipo', 'pessoa_id'];

    protected function casts(): array
    {
        return ['pessoa_id' => 'integer'];
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(ProjetoDocumento::class, 'projeto_documento_id');
    }
}
