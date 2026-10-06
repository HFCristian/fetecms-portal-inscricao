<?php

namespace App\Services;

use App\Enums\TipoDocumento;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

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

    /**
     * Todos os documentos do projeto num ZIP só (Sprint 167): o orientador
     * baixa de uma vez o que enviou na inscrição — e o termo do finalista, se
     * houver —, em vez de abrir arquivo por arquivo.
     *
     * Cada arquivo leva o tipo na frente do nome original ("Projeto de Pesquisa
     * - plano.pdf"): dois anexos com o mesmo nome não se sobrescrevem e a pasta
     * se lê sem abrir nada. Arquivo que sumiu do storage fica de fora — e se
     * nenhum sobrar, é recusa, não um ZIP vazio.
     *
     * O arquivo é montado em disco temporário porque o `ZipArchive` só escreve
     * em arquivo; quem chama devolve e apaga.
     */
    public function zip(Projeto $projeto): string
    {
        $documentos = $projeto->documentos()->orderBy('tipo')->orderBy('id')->get()
            ->filter(fn (ProjetoDocumento $d) => Storage::disk($d->disk)->exists($d->path));

        if ($documentos->isEmpty()) {
            throw ValidationException::withMessages([
                'documentos' => 'Este projeto não tem documentos disponíveis para baixar.',
            ]);
        }

        $caminho = tempnam(sys_get_temp_dir(), 'documentos-').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages([
                'documentos' => 'Não foi possível montar o arquivo compactado.',
            ]);
        }

        $usados = [];

        foreach ($documentos as $documento) {
            $nome = $this->nomeNoZip($documento, $usados);
            $zip->addFromString($nome, (string) Storage::disk($documento->disk)->get($documento->path));
        }

        $zip->close();

        return $caminho;
    }

    /** O nome do ZIP para baixar: "documentos-12-titulo-do-projeto.zip". */
    public function nomeDoZip(Projeto $projeto): string
    {
        return 'documentos-'.$projeto->id.'-'.Str::limit(Str::slug($projeto->titulo ?? 'projeto'), 60, '').'.zip';
    }

    /**
     * "Tipo - nome original", sem barra nem caractere que o Windows recusa, e
     * com um sufixo quando o mesmo nome já entrou.
     *
     * @param  array<string, true>  $usados
     */
    private function nomeNoZip(ProjetoDocumento $documento, array &$usados): string
    {
        $original = $documento->nome_original ?: 'arquivo';
        $base = trim(preg_replace('#[\\\\/:*?"<>|]+#', '-', $documento->tipo->label().' - '.$original));
        $extensao = pathinfo($base, PATHINFO_EXTENSION);
        $semExtensao = $extensao === '' ? $base : substr($base, 0, -strlen($extensao) - 1);

        $nome = $base;
        for ($i = 2; isset($usados[mb_strtolower($nome)]); $i++) {
            $nome = $semExtensao." ({$i})".($extensao === '' ? '' : '.'.$extensao);
        }

        $usados[mb_strtolower($nome)] = true;

        return $nome;
    }
}
