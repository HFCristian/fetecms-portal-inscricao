<?php

namespace App\Services;

use App\Enums\TipoDocumento;
use App\Models\ListaFinal;
use App\Models\ProjetoDocumento;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use ZipArchive;

/**
 * Lista final → **Projetos de pesquisa** (Sprint 187): o arquivo do projeto de
 * pesquisa de cada finalista num ZIP só — de todos, ou só dos escolhidos.
 *
 * Quem pede é a organização montando o material das bancas e do anuário: ela
 * precisa do documento que o avaliador leu, separado do jeito que a feira se
 * divide. Por isso o ZIP sai em **pastas categoria → área**, e cada arquivo é
 * nomeado pelo **código do projeto na lista** (FET.AGR-001 - Título.pdf) — é o
 * código que a organização usa para falar do projeto no evento, e ordena a
 * pasta na mesma ordem da lista.
 *
 * Os arquivos entram **sem recompressão** (PDF já é comprimido) e **por
 * caminho**, nunca lidos para a memória: uma lista final tem centenas de
 * projetos e o `addFromString` seguraria todos eles na RAM até o `close()`.
 *
 * Projeto sem o arquivo — não enviou, ou o arquivo sumiu do storage — não some
 * calado: o ZIP leva um `_projetos-sem-arquivo.txt` com quem ficou de fora.
 */
class ExportacaoProjetosPesquisaService
{
    public const ARQUIVO_FALTANTES = '_projetos-sem-arquivo.txt';

    public function __construct(private readonly ListaFinalService $listas) {}

    /**
     * Monta o ZIP. `$projetoIds` nulo = a lista inteira; os ids que não estão
     * na lista são ignorados.
     *
     * O arquivo é montado em disco temporário porque o `ZipArchive` só escreve
     * em arquivo; quem chama devolve e apaga.
     *
     * @param  list<int>|null  $projetoIds
     * @return array{caminho: string, nome: string}
     */
    public function zip(ListaFinal $lista, ?array $projetoIds = null): array
    {
        $itens = $this->listas->itensDaLista($lista);

        if ($projetoIds !== null) {
            $escolhidos = array_flip(array_map('intval', $projetoIds));
            $itens = array_values(array_filter($itens, fn (array $i) => isset($escolhidos[$i['projeto_id']])));
        }

        if ($itens === []) {
            throw ValidationException::withMessages([
                'projeto_ids' => 'Nenhum dos projetos escolhidos está nesta lista.',
            ]);
        }

        $documentos = ProjetoDocumento::whereIn('projeto_id', array_column($itens, 'projeto_id'))
            ->where('tipo', TipoDocumento::PlanoPesquisa)
            ->orderBy('id')
            ->get()
            ->filter(fn (ProjetoDocumento $d) => Storage::disk($d->disk)->exists($d->path))
            ->groupBy('projeto_id');

        if ($documentos->isEmpty()) {
            throw ValidationException::withMessages([
                'projeto_ids' => 'Nenhum dos projetos escolhidos tem o arquivo do projeto de pesquisa disponível.',
            ]);
        }

        $caminho = tempnam(sys_get_temp_dir(), 'projetos-pesquisa-').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages([
                'projeto_ids' => 'Não foi possível montar o arquivo compactado.',
            ]);
        }

        $usados = [];
        $faltantes = [];
        $temporarios = [];

        foreach ($itens as $item) {
            $docs = $documentos->get($item['projeto_id']);

            if ($docs === null) {
                $faltantes[] = $this->rotulo($item);

                continue;
            }

            $pasta = $this->segmento($item['categoria'] ?: 'Sem categoria').'/'.$this->segmento($item['area'] ?: 'Sem área');

            foreach ($docs as $documento) {
                $nome = $this->nomeUnico($pasta, $this->rotulo($item), $this->extensao($documento), $usados);
                $origem = $this->caminhoLocal($documento, $temporarios);

                $zip->addFile($origem, $nome);
                $zip->setCompressionName($nome, ZipArchive::CM_STORE);
            }
        }

        if ($faltantes !== []) {
            $zip->addFromString(self::ARQUIVO_FALTANTES, implode("\r\n", [
                'Projetos da lista sem o arquivo do projeto de pesquisa disponível:',
                '',
                ...$faltantes,
            ])."\r\n");
        }

        $zip->close();

        foreach ($temporarios as $temporario) {
            @unlink($temporario);
        }

        return [
            'caminho' => $caminho,
            'nome' => 'projetos-de-pesquisa-'.Str::limit(Str::slug($lista->nome ?: 'lista'), 60, '').'.zip',
        ];
    }

    /** "FET.AGR-001 - Título", ou só o título quando o projeto está sem código. */
    private function rotulo(array $item): string
    {
        $titulo = trim((string) ($item['titulo'] ?: 'Projeto '.$item['projeto_id']));

        return trim(($item['codigo'] !== '' ? $item['codigo'].' - ' : '').$titulo);
    }

    /**
     * O arquivo no disco local. Storage remoto (S3) é copiado para um
     * temporário, apagado depois do `close()` — que é quando o ZIP lê.
     *
     * @param  list<string>  $temporarios
     */
    private function caminhoLocal(ProjetoDocumento $documento, array &$temporarios): string
    {
        $disco = Storage::disk($documento->disk);

        if ($disco->getAdapter() instanceof LocalFilesystemAdapter) {
            return $disco->path($documento->path);
        }

        $temporario = tempnam(sys_get_temp_dir(), 'projeto-pesquisa-');
        $destino = fopen($temporario, 'wb');
        $origem = $disco->readStream($documento->path);
        stream_copy_to_stream($origem, $destino);
        fclose($origem);
        fclose($destino);
        $temporarios[] = $temporario;

        return $temporario;
    }

    private function extensao(ProjetoDocumento $documento): string
    {
        $extensao = pathinfo((string) $documento->nome_original, PATHINFO_EXTENSION)
            ?: pathinfo((string) $documento->path, PATHINFO_EXTENSION);

        return mb_strtolower($extensao ?: 'pdf');
    }

    /**
     * O nome dentro da pasta, com "(2)" quando o projeto tem mais de um
     * arquivo — ou dois títulos dão no mesmo nome.
     *
     * @param  array<string, true>  $usados
     */
    private function nomeUnico(string $pasta, string $rotulo, string $extensao, array &$usados): string
    {
        $base = $pasta.'/'.$this->segmento($rotulo, 100);
        $nome = "{$base}.{$extensao}";

        for ($i = 2; isset($usados[mb_strtolower($nome)]); $i++) {
            $nome = "{$base} ({$i}).{$extensao}";
        }

        $usados[mb_strtolower($nome)] = true;

        return $nome;
    }

    /** Um pedaço de caminho sem barra nem caractere que o Windows recusa. */
    private function segmento(string $texto, int $limite = 80): string
    {
        $limpo = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('#[\\\\/:*?"<>|]+#', '-', $texto)));

        return rtrim(mb_substr($limpo, 0, $limite), ' .') ?: 'projeto';
    }
}
