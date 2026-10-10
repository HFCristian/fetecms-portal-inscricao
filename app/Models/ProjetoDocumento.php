<?php

namespace App\Models;

use App\Enums\TipoDocumento;
use Database\Factories\ProjetoDocumentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjetoDocumento extends Model
{
    /** @use HasFactory<ProjetoDocumentoFactory> */
    use HasFactory;

    protected $table = 'projeto_documentos';

    protected $fillable = [
        'projeto_id', 'tipo', 'item_conferencia_id', 'disk', 'path', 'nome_original', 'mime', 'tamanho_bytes',
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

    /**
     * O item da lista única que este arquivo atende (Sprint 181) — só nos
     * documentos enviados antes do evento, pela aba Documentos.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemConferencia::class, 'item_conferencia_id');
    }

    /**
     * Para quem o arquivo vale, quando o item é de pessoa (Sprint 183): o
     * mesmo projeto pode mandar um arquivo por pessoa ou um para a equipe.
     */
    public function pessoas(): HasMany
    {
        return $this->hasMany(ProjetoDocumentoPessoa::class, 'projeto_documento_id');
    }
}
