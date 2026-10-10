<?php

namespace App\Services;

use App\Models\ItemConferencia;
use App\Models\ProjetoDocumento;
use App\Models\ProjetoDocumentoPessoa;
use Carbon\CarbonInterface;

/**
 * Os arquivos que o orientador mandou **antes do evento** para os itens da
 * lista única marcados "receber antes" (Sprint 183), em lote.
 *
 * A mesma pergunta — "isto já chegou?" — é feita na aba Documentos, na ficha
 * do credenciamento e na da checagem, e por isso a resposta mora aqui, uma
 * consulta para a página inteira. Item do projeto está coberto com qualquer
 * arquivo; item de pessoa, pessoa a pessoa, pelos arquivos que dizem valer
 * para ela.
 */
class EnviosAntecipadosService
{
    /**
     * Os arquivos de cada projeto, por item: `[projeto_id][item_id] => list`.
     * Com `$corte`, só os enviados até aquele instante.
     *
     * @param  iterable<int>  $projetoIds
     * @return array<int, array<int, list<array<string, mixed>>>>
     */
    public function arquivos(iterable $projetoIds, ?CarbonInterface $corte = null): array
    {
        $ids = collect($projetoIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $porProjeto = [];

        ProjetoDocumento::query()
            ->whereIn('projeto_id', $ids)
            ->whereNotNull('item_conferencia_id')
            ->when($corte !== null, fn ($q) => $q->where('created_at', '<=', $corte))
            ->with('pessoas')
            ->orderBy('id')
            ->get()
            ->each(function (ProjetoDocumento $d) use (&$porProjeto) {
                $porProjeto[$d->projeto_id][$d->item_conferencia_id][] = $this->resumo($d);
            });

        return $porProjeto;
    }

    /**
     * Os arquivos que cobrem cada pessoa (ou o projeto) num item: a chave é
     * "papel:id" — ou "projeto:id" no item do projeto, o mesmo formato das
     * marcações do balcão.
     *
     * @param  list<array<string, mixed>>  $arquivos  os de um item, de `arquivos()`
     * @return array<string, list<array{id:int, nome_original:string, enviado_em:?string}>>
     */
    public function cobertura(ItemConferencia $item, int $projetoId, array $arquivos): array
    {
        $cobre = [];

        foreach ($arquivos as $arquivo) {
            $chaves = $item->doProjeto() ? [ItemConferencia::PROJETO.':'.$projetoId] : $arquivo['pessoas'];

            foreach ($chaves as $chave) {
                $cobre[$chave][] = [
                    'id' => $arquivo['id'],
                    'nome_original' => $arquivo['nome_original'],
                    'enviado_em' => $arquivo['enviado_em'],
                ];
            }
        }

        return $cobre;
    }

    /** @return array<string, mixed> */
    private function resumo(ProjetoDocumento $d): array
    {
        return [
            'id' => $d->id,
            'nome_original' => $d->nome_original,
            'mime' => $d->mime,
            'tamanho_bytes' => $d->tamanho_bytes,
            'enviado_em' => $d->created_at?->toIso8601String(),
            'pessoas' => $d->pessoas
                ->map(fn (ProjetoDocumentoPessoa $p) => $p->pessoa_tipo.':'.$p->pessoa_id)
                ->values()
                ->all(),
        ];
    }
}
