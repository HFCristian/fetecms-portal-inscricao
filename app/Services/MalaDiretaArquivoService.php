<?php

namespace App\Services;

use App\Models\MalaDireta;
use App\Models\MalaDiretaArquivo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Imagens do corpo e anexos da mala direta.
 *
 * O admin sobe o arquivo enquanto escreve — antes de a mala existir —, então
 * ele nasce solto e só é vinculado no disparo. O que nunca for vinculado vira
 * lixo: a limpeza roda no upload seguinte, apagando os órfãos de mais de um dia.
 *
 * O disco é o privado: a imagem do corpo é servida por rota autenticada no
 * painel e vai **embutida** (CID) no e-mail, nunca por link público.
 */
class MalaDiretaArquivoService
{
    private const DISK = 'local';

    /** Órfão com mais de um dia some — tempo de sobra para uma redação longa. */
    private const HORAS_ORFAO = 24;

    public function armazenar(UploadedFile $arquivo, string $tipo, User $autor): MalaDiretaArquivo
    {
        $this->limparOrfaos();

        $path = $arquivo->store('mala-direta/'.$tipo, self::DISK);

        return MalaDiretaArquivo::create([
            'tipo' => $tipo,
            'user_id' => $autor->id,
            'disk' => self::DISK,
            'path' => $path,
            'nome_original' => $arquivo->getClientOriginalName(),
            'mime' => $arquivo->getClientMimeType(),
            'tamanho_bytes' => $arquivo->getSize(),
        ]);
    }

    /**
     * Prende à mala os arquivos escolhidos. Só entra arquivo ainda solto — um
     * já usado em outra mala não é reaproveitado, para o relatório de cada
     * disparo continuar íntegro.
     *
     * @param  list<int>  $ids
     */
    public function vincular(MalaDireta $mala, array $ids, string $tipo): void
    {
        if ($ids === []) {
            return;
        }

        $this->conferirQuantidade($ids, $tipo);

        MalaDiretaArquivo::whereIn('id', $ids)
            ->where('tipo', $tipo)
            ->whereNull('mala_direta_id')
            ->update(['mala_direta_id' => $mala->id]);
    }

    /**
     * Copia para uma mala os arquivos ainda soltos, **sem tomá-los** de quem
     * está escrevendo. É o que o envio de teste usa: o disparo de verdade vem
     * depois e precisa encontrar os originais livres para vincular.
     *
     * A linha é nova (id novo); o arquivo em disco é o mesmo, e ninguém o apaga
     * — a faxina só alcança órfão, e `remover()` recusa arquivo já vinculado.
     *
     * @param  list<int>  $ids
     * @return array<int, int> id original => id da cópia
     */
    public function copiar(MalaDireta $mala, array $ids, string $tipo): array
    {
        if ($ids === []) {
            return [];
        }

        $this->conferirQuantidade($ids, $tipo);

        $copias = [];

        MalaDiretaArquivo::whereIn('id', $ids)
            ->where('tipo', $tipo)
            ->whereNull('mala_direta_id')
            ->get()
            ->each(function (MalaDiretaArquivo $arquivo) use ($mala, &$copias) {
                $copia = $arquivo->replicate();
                $copia->mala_direta_id = $mala->id;
                $copia->save();

                $copias[$arquivo->id] = $copia->id;
            });

        return $copias;
    }

    /** Apaga um arquivo ainda não vinculado (o admin removeu da mensagem). */
    public function remover(MalaDiretaArquivo $arquivo): void
    {
        if ($arquivo->mala_direta_id !== null) {
            throw ValidationException::withMessages([
                'arquivo' => 'Este arquivo já foi enviado numa mala direta e não pode ser apagado.',
            ]);
        }

        Storage::disk($arquivo->disk)->delete($arquivo->path);
        $arquivo->delete();
    }

    /**
     * O limite de arquivos por mensagem, valendo tanto para vincular quanto
     * para copiar.
     *
     * @param  list<int>  $ids
     */
    private function conferirQuantidade(array $ids, string $tipo): void
    {
        $maximo = MalaDiretaArquivo::maximoDe($tipo);

        if (count($ids) > $maximo) {
            throw ValidationException::withMessages([
                $tipo === MalaDiretaArquivo::TIPO_IMAGEM ? 'imagens' : 'anexos' => "No máximo {$maximo} arquivos por mensagem.",
            ]);
        }
    }

    /** Limpa o que ficou para trás de mensagens que nunca foram disparadas. */
    public function limparOrfaos(): void
    {
        MalaDiretaArquivo::whereNull('mala_direta_id')
            ->where('created_at', '<', now()->subHours(self::HORAS_ORFAO))
            ->get()
            ->each(function (MalaDiretaArquivo $arquivo) {
                Storage::disk($arquivo->disk)->delete($arquivo->path);
                $arquivo->delete();
            });
    }
}
