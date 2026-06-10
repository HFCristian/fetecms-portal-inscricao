<?php

namespace App\Services;

use App\Enums\TipoDocumento;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentoService
{
    private const DISK = 'local'; // disco privado (storage/app/private) — nunca público

    public function armazenar(Projeto $projeto, UploadedFile $file, TipoDocumento $tipo): ProjetoDocumento
    {
        $path = $file->store("projetos/{$projeto->id}", self::DISK);

        return $projeto->documentos()->create([
            'tipo' => $tipo,
            'disk' => self::DISK,
            'path' => $path,
            'nome_original' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'tamanho_bytes' => $file->getSize(),
        ]);
    }

    public function remover(ProjetoDocumento $documento): void
    {
        Storage::disk($documento->disk)->delete($documento->path);
        $documento->delete();
    }
}
