<?php

namespace App\Models;

use App\Enums\TipoPessoaCredenciamento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um item da **lista única** do credenciamento e da checagem dos estandes
 * (Sprint 181, Parametrização → Itens do credenciamento e da checagem).
 *
 * O item diz de quem é — de cada **pessoa** dos papéis marcados, ou do
 * **projeto** —, onde é conferido — no credenciamento, na checagem ou nos
 * dois — e se a organização quer **recebê-lo antes**, enviado pelo orientador
 * na aba Documentos. É catálogo do portal (vale para todas as edições) e, uma
 * vez conferido, é desativado em vez de excluído.
 *
 * Dois itens são **do sistema** (`chave`): o termo de responsabilidade (do
 * projeto) e o termo de uso de imagem (de cada estudante). Eles têm regras que
 * o portal conhece — o público "finalistas sem o termo", o Mapa do Evento —,
 * então se renomeiam e se ligam/desligam, mas não se excluem nem mudam de
 * abrangência.
 */
class ItemConferencia extends Model
{
    protected $table = 'itens_conferencia';

    public const PESSOA = 'pessoa';

    public const PROJETO = 'projeto';

    public const CHAVE_TERMO_IMAGEM = 'termo_uso_imagem';

    public const CHAVE_TERMO_RESPONSABILIDADE = 'termo_responsabilidade';

    protected $fillable = [
        'nome', 'descricao', 'abrangencia', 'papeis', 'no_credenciamento', 'na_checagem',
        'receber_antes', 'ordem', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'papeis' => 'array',
            'no_credenciamento' => 'boolean',
            'na_checagem' => 'boolean',
            'receber_antes' => 'boolean',
            'ordem' => 'integer',
            'ativo' => 'boolean',
        ];
    }

    public function doProjeto(): bool
    {
        return $this->abrangencia === self::PROJETO;
    }

    public function doSistema(): bool
    {
        return $this->chave !== null;
    }

    public function ehTermoImagem(): bool
    {
        return $this->chave === self::CHAVE_TERMO_IMAGEM;
    }

    /** O item é conferido nesta pessoa? (Item do projeto não é de ninguém.) */
    public function valePara(TipoPessoaCredenciamento|string $papel): bool
    {
        $papel = $papel instanceof TipoPessoaCredenciamento ? $papel->value : $papel;

        return ! $this->doProjeto() && in_array($papel, $this->papeis ?? [], true);
    }

    /** Conferências do balcão feitas com este item — o que impede excluí-lo. */
    public function credenciamentos(): HasMany
    {
        return $this->hasMany(CredenciamentoDocumento::class, 'item_id');
    }

    /** Conferências de estande feitas com este item. */
    public function checagens(): HasMany
    {
        return $this->hasMany(ChecagemEstandeItem::class, 'item_id');
    }

    /** Arquivos que o orientador já enviou para este item. */
    public function arquivos(): HasMany
    {
        return $this->hasMany(ProjetoDocumento::class, 'item_conferencia_id');
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true)->orderBy('ordem')->orderBy('id');
    }

    public function scopeNoCredenciamento(Builder $query): Builder
    {
        return $query->ativos()->where('no_credenciamento', true);
    }

    public function scopeNaChecagem(Builder $query): Builder
    {
        return $query->ativos()->where('na_checagem', true);
    }

    /** O item do sistema com esta chave. */
    public static function doSistemaPorChave(string $chave): ?self
    {
        return static::where('chave', $chave)->first();
    }
}
