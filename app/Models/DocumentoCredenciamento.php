<?php

namespace App\Models;

use App\Enums\TipoPessoaCredenciamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um documento exigido no credenciamento, por papel (Parametrização →
 * Credenciamento). A lista é catálogo do portal: vale para todas as edições e
 * é o que o balcão confere pessoa a pessoa.
 */
class DocumentoCredenciamento extends Model
{
    protected $table = 'documentos_credenciamento';

    protected $fillable = ['tipo_pessoa', 'nome', 'ordem', 'ativo'];

    protected function casts(): array
    {
        return [
            'tipo_pessoa' => TipoPessoaCredenciamento::class,
            'ordem' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    /** Conferências já feitas com este documento — o que impede excluí-lo. */
    public function credenciamentos(): HasMany
    {
        return $this->hasMany(CredenciamentoDocumento::class, 'documento_credenciamento_id');
    }

    /** Os exigidos de um papel, na ordem em que aparecem no balcão. */
    public function scopeDoTipo($query, TipoPessoaCredenciamento $tipo)
    {
        return $query->where('tipo_pessoa', $tipo->value)->where('ativo', true)->orderBy('ordem')->orderBy('id');
    }
}
