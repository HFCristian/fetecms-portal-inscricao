<?php

namespace App\Models;

use App\Enums\TipoDocumento;
use Database\Factories\ProjetoDocumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjetoDocumento extends Model
{
    /** @use HasFactory<ProjetoDocumentoFactory> */
    use HasFactory;

    protected $table = 'projeto_documentos';

    protected $fillable = [
        'projeto_id', 'tipo', 'disk', 'path', 'nome_original', 'mime', 'tamanho_bytes',
        'assinatura_valida', 'assinatura',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumento::class,
            'tamanho_bytes' => 'integer',
            // Nulo = assinatura não conferida (documento de outro tipo, ou
            // anexado antes de a conferência existir).
            'assinatura_valida' => 'boolean',
            'assinatura' => 'array',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class);
    }
}
