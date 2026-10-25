<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma lista de certificados (Sprints 259–260): o que o assistente da aba
 * Certificados decidiu — fases, público, carga horária — e, depois de gerada,
 * o retrato de quem recebe ({@see CertificadoLinha}).
 *
 * `config` guarda as escolhas do assistente e `nomes` as correções de nome
 * feitas em "Conferir nomes", por pessoa (`user:12`, `aluno:5`,
 * `coorientador:3`). A correção vale só aqui: o cadastro não muda.
 *
 * `alterada_em` marca a lista gerada que foi editada depois: o retrato não
 * reflete mais a configuração até ser gerado de novo.
 */
class CertificadoLista extends Model
{
    protected $table = 'certificado_listas';

    public const RASCUNHO = 'rascunho';

    public const GERADA = 'gerada';

    protected $fillable = [
        'edicao_id', 'nome', 'status', 'passo', 'config', 'nomes', 'criada_por', 'gerada_por',
        'gerada_em', 'alterada_em', 'total_pessoas', 'total_certificados',
    ];

    protected $attributes = ['status' => self::RASCUNHO, 'passo' => 0];

    protected function casts(): array
    {
        return [
            'passo' => 'integer',
            'config' => 'array',
            'nomes' => 'array',
            'gerada_em' => 'datetime',
            'alterada_em' => 'datetime',
            'total_pessoas' => 'integer',
            'total_certificados' => 'integer',
        ];
    }

    public function gerada(): bool
    {
        return $this->status === self::GERADA;
    }

    public function edicao(): BelongsTo
    {
        return $this->belongsTo(Edicao::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    public function gerador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerada_por');
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(CertificadoLinha::class, 'lista_id');
    }
}
