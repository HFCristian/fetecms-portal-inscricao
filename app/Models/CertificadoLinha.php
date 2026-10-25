<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um certificado de uma lista gerada: a pessoa, o que ela fez e a carga
 * horária, como estavam no momento em que a lista foi gerada.
 *
 * Tudo vai desnormalizado — nome, CPF, título do projeto —, porque é o que foi
 * para o SICERT: o cadastro pode mudar depois, o certificado emitido não.
 * `nome_original` é o do cadastro; `nome` já traz a correção da lista.
 */
class CertificadoLinha extends Model
{
    protected $table = 'certificado_linhas';

    protected $fillable = [
        'lista_id', 'categoria', 'pessoa_chave', 'nome', 'nome_original', 'cpf', 'email', 'telefone',
        'funcao', 'tipo_atividade', 'titulo', 'campo_generico', 'quantidade', 'minutos', 'teto_aplicado',
        'data_inicio', 'data_fim', 'projeto_id', 'ordem',
    ];

    protected function casts(): array
    {
        return [
            'quantidade' => 'integer',
            'minutos' => 'integer',
            'teto_aplicado' => 'boolean',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'ordem' => 'integer',
        ];
    }

    public function lista(): BelongsTo
    {
        return $this->belongsTo(CertificadoLista::class, 'lista_id');
    }
}
