<?php

namespace App\Models;

use App\Enums\TipoPessoaCredenciamento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um documento exigido no credenciamento, por papel (Parametrização →
 * Credenciamento). A lista é catálogo do portal: vale para todas as edições e
 * é o que o balcão confere pessoa a pessoa.
 *
 * Um item é **do sistema** (Sprint 175): o termo de uso de imagem do
 * estudante, reconhecido pela `chave`, que a ficha já traz marcado quando o
 * orientador o enviou online. Ele pode ser renomeado e desativado, mas não
 * excluído — sem ele o envio antecipado não chegaria ao balcão.
 */
class DocumentoCredenciamento extends Model
{
    protected $table = 'documentos_credenciamento';

    /** O termo de uso de imagem do estudante (Sprint 175). */
    public const CHAVE_TERMO_IMAGEM = 'termo_uso_imagem';

    protected $fillable = ['tipo_pessoa', 'nome', 'ordem', 'ativo'];

    protected function casts(): array
    {
        return [
            'tipo_pessoa' => TipoPessoaCredenciamento::class,
            'ordem' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function doSistema(): bool
    {
        return $this->chave !== null;
    }

    public function ehTermoImagem(): bool
    {
        return $this->chave === self::CHAVE_TERMO_IMAGEM;
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
