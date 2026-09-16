<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um item do catálogo de checagem de estandes (aba Avaliação presencial).
 *
 * É catálogo do portal, como a lista de documentos do credenciamento: vale para
 * todas as edições, e item já conferido em alguma checagem é **desativado**, não
 * excluído — apagá-lo deixaria a conferência de um estande sem o nome do que
 * foi conferido.
 */
class ItemChecagemEstande extends Model
{
    protected $table = 'itens_checagem_estande';

    protected $fillable = ['nome', 'descricao', 'ordem', 'ativo'];

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
            'ativo' => 'boolean',
        ];
    }
}
