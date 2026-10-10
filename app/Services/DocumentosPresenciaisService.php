<?php

namespace App\Services;

use App\Enums\SituacaoTermoImagem;
use App\Enums\TipoDocumento;
use App\Models\Edicao;
use App\Models\ItemConferencia;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\ProjetoDocumento;
use App\Models\User;
use App\Support\AssinaturaPdf;
use App\Support\EquipeProjeto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aba "Documentos" do orientador: o que a organização quer receber **antes do
 * evento**.
 *
 * Desde a Sprint 183 a aba não conhece documento nenhum de cor: ela mostra os
 * itens da lista única (Parametrização → Itens do credenciamento e da
 * checagem) marcados **"receber antes"** — os dois termos do finalista, que são
 * itens do sistema, e o que mais a organização pedir. Cada item aceita **um ou
 * mais arquivos** (PDF, JPG ou PNG): a escola às vezes tem um papel da turma,
 * às vezes um por pessoa. No item de pessoa, cada arquivo diz **para quem
 * vale**, e é isso que o credenciamento e a checagem leem para já marcar quem
 * está coberto.
 *
 * Só **projeto finalista** envia, da publicação da lista final ao fim do
 * evento. Nada é obrigatório online: quem não enviar apresenta no balcão. Todo
 * PDF passa pela conferência de assinatura (`AssinaturaPdf`), que só informa —
 * o arquivo nunca é recusado por ela.
 */
class DocumentosPresenciaisService
{
    /** Tamanho máximo do arquivo — o mesmo dos anexos da inscrição. */
    public const MAX_KB = 10240;

    /** Arquivos aceitos: o PDF e a foto do papel assinado. */
    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(private readonly DocumentoService $documentos) {}

    /**
     * Estado da janela para esta pessoa.
     *
     * @return array<string, mixed>
     */
    public function janela(User $user, bool $teste = false): array
    {
        $edicao = Edicao::atual();
        $modoTeste = $teste && (bool) $user->is_demo;
        $lista = ListaFinal::vigente($edicao, $modoTeste);

        $encerrado = (bool) $edicao?->eventoEncerrado();

        return [
            // Sem lista final publicada não há finalista — logo, não há o que enviar.
            'aberta' => $lista !== null && ($modoTeste || ! $encerrado),
            'tem_lista' => $lista !== null,
            'encerrada' => $encerrado,
            'evento_de_label' => $edicao?->evento_de?->format('d/m/Y H:i'),
            'evento_ate_label' => $edicao?->evento_ate?->format('d/m/Y H:i'),
            'modo_teste' => $modoTeste,
            'is_demo' => (bool) $user->is_demo,
            'max_kb' => self::MAX_KB,
        ];
    }

    /** Barra quem tenta enviar fora da janela (a tela já esconde o botão). */
    public function garantirJanelaAberta(User $user, bool $teste = false): void
    {
        $janela = $this->janela($user, $teste);

        if (! $janela['aberta']) {
            throw ValidationException::withMessages([
                'periodo' => $janela['tem_lista']
                    ? 'O período de envio de documentos está encerrado.'
                    : 'A lista final ainda não foi publicada.',
            ]);
        }
    }

    /**
     * Os projetos **finalistas** deste orientador, cada um com os itens que a
     * organização quer receber antes e o que já foi enviado para cada um.
     *
     * @return list<array<string, mixed>>
     */
    public function projetos(User $orientador, bool $teste = false): array
    {
        $ids = $this->finalistas($orientador, $teste);

        if ($ids->isEmpty()) {
            return [];
        }

        $itens = $this->itensRecebidosAntes();
        $arquivos = ProjetoDocumento::query()
            ->whereIn('projeto_id', $ids)
            ->whereNotNull('item_conferencia_id')
            ->with('pessoas')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ProjetoDocumento $d) => $d->projeto_id.':'.$d->item_conferencia_id);

        return Projeto::query()
            ->whereIn('id', $ids)
            ->with(['area:id,nome', 'alunos:id,projeto_id,nome', 'user:id,name', 'coorientador'])
            ->orderBy('titulo')
            ->get()
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'area' => $p->area?->nome,
                'categoria' => $p->categoria?->label(),
                'itens' => $itens->map(fn (ItemConferencia $item) => $this->secao(
                    $p, $item, $arquivos->get($p->id.':'.$item->id, collect()),
                ))->values()->all(),
            ])
            ->all();
    }

    /**
     * Envia um arquivo para um item. Os anteriores ficam: o projeto pode ter um
     * arquivo por pessoa, ou a frente e o verso do mesmo papel.
     *
     * @param  array<int, mixed>  $pessoas  "papel:id" de quem o arquivo cobre (item de pessoa)
     * @return array<string, mixed> a seção do item depois do envio
     */
    public function anexar(Projeto $projeto, ItemConferencia $item, UploadedFile $arquivo, array $pessoas, User $autor, bool $teste = false): array
    {
        $this->garantirItemRecebidoAntes($item);
        $this->garantirJanelaAberta($autor, $teste);
        $this->garantirFinalista($projeto, $autor, $teste);
        $pessoas = $this->pessoasDoItem($projeto, $item, $pessoas);

        DB::transaction(function () use ($projeto, $item, $arquivo, $pessoas) {
            $documento = $this->documentos->armazenar($projeto, $arquivo, TipoDocumento::doItem($item->chave));
            $documento->forceFill(['item_conferencia_id' => $item->id])->save();

            // A conferência da assinatura digital só faz sentido no PDF — a foto
            // do papel assinado à mão não tem o que conferir, e nem por isso vale menos.
            if ($this->ehPdf($documento)) {
                $laudo = AssinaturaPdf::conferir((string) file_get_contents($arquivo->getRealPath()));
                $documento->forceFill(['assinatura_valida' => $laudo['valida'], 'assinatura' => $laudo])->save();
            }

            $this->gravarPessoas($documento, $pessoas);
        });

        return $this->secaoAtual($projeto, $item);
    }

    /**
     * Troca para quem um arquivo vale — o orientador marcou errado e não
     * precisa reenviar o arquivo por isso.
     *
     * @param  array<int, mixed>  $pessoas
     * @return array<string, mixed>
     */
    public function definirPessoas(ProjetoDocumento $documento, array $pessoas, User $autor, bool $teste = false): array
    {
        [$projeto, $item] = $this->arquivoDoOrientador($documento, $autor, $teste);

        if ($item->doProjeto()) {
            throw ValidationException::withMessages(['pessoas' => 'Este item é do projeto: o arquivo não é de uma pessoa.']);
        }

        $this->gravarPessoas($documento, $this->pessoasDoItem($projeto, $item, $pessoas));

        return $this->secaoAtual($projeto, $item);
    }

    /**
     * Remove um arquivo; quem só ele cobria volta a faltar.
     *
     * @return array<string, mixed>
     */
    public function remover(ProjetoDocumento $documento, User $autor, bool $teste = false): array
    {
        [$projeto, $item] = $this->arquivoDoOrientador($documento, $autor, $teste);

        $this->documentos->remover($documento);

        return $this->secaoAtual($projeto, $item);
    }

    /** @return Collection<int, ItemConferencia> */
    private function itensRecebidosAntes(): Collection
    {
        return ItemConferencia::ativos()->where('receber_antes', true)->get();
    }

    /**
     * A seção de um item já recarregada, para a resposta do envio.
     *
     * @return array<string, mixed>
     */
    private function secaoAtual(Projeto $projeto, ItemConferencia $item): array
    {
        $arquivos = ProjetoDocumento::where('projeto_id', $projeto->id)
            ->where('item_conferencia_id', $item->id)
            ->with('pessoas')
            ->orderBy('id')
            ->get();

        return $this->secao($projeto->fresh(['alunos:id,projeto_id,nome', 'user:id,name', 'coorientador']), $item, $arquivos);
    }

    /**
     * Um item na aba: quem ele alcança, os arquivos e a cobertura.
     *
     * @param  Collection<int, ProjetoDocumento>  $arquivos
     * @return array<string, mixed>
     */
    private function secao(Projeto $projeto, ItemConferencia $item, Collection $arquivos): array
    {
        $pessoas = $item->doProjeto()
            ? []
            : array_values(array_filter(EquipeProjeto::de($projeto), fn (array $p) => $item->valePara($p['tipo'])));
        $nomes = array_column($pessoas, 'nome', 'chave');

        $cobertas = $arquivos
            ->flatMap(fn (ProjetoDocumento $d) => $d->pessoas->map(fn ($p) => $p->pessoa_tipo.':'.$p->pessoa_id))
            ->unique()
            ->intersect(array_keys($nomes))
            ->values();

        [$cobertos, $total] = $item->doProjeto()
            ? [$arquivos->isEmpty() ? 0 : 1, 1]
            : [$cobertas->count(), count($pessoas)];
        $situacao = SituacaoTermoImagem::de($cobertos, $total);

        return [
            'id' => $item->id,
            'nome' => $item->nome,
            'descricao' => $item->descricao,
            'abrangencia' => $item->abrangencia,
            'chave' => $item->chave,
            'pessoas' => $pessoas,
            'situacao' => $situacao->value,
            'situacao_label' => $item->doProjeto()
                ? ($cobertos > 0 ? 'Enviado' : 'Pendente')
                : $situacao->label(),
            'cobertos' => $cobertos,
            'total' => $total,
            'faltando' => array_values(array_diff_key($nomes, array_flip($cobertas->all()))),
            'arquivos' => $arquivos->map(fn (ProjetoDocumento $d) => $this->formatar($d) + [
                'pdf' => $this->ehPdf($d),
                'pessoas' => $d->pessoas
                    ->map(fn ($p) => $p->pessoa_tipo.':'.$p->pessoa_id)
                    ->filter(fn (string $chave) => isset($nomes[$chave]))
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }

    /**
     * O projeto e o item do arquivo, se ele é um envio antecipado de um
     * finalista deste orientador e a janela está aberta. Arquivo de inscrição
     * responde como inexistente — estas rotas não mexem nele.
     *
     * @return array{0: Projeto, 1: ItemConferencia}
     */
    private function arquivoDoOrientador(ProjetoDocumento $documento, User $autor, bool $teste): array
    {
        $item = $documento->item;

        if ($item === null || ! $documento->tipo->doEvento()) {
            abort(404);
        }

        $projeto = $documento->projeto;

        $this->garantirJanelaAberta($autor, $teste);
        $this->garantirFinalista($projeto, $autor, $teste);

        return [$projeto, $item];
    }

    /**
     * Quem o arquivo cobre, conferido contra a equipe e os papéis do item: ao
     * menos uma pessoa, e ninguém de fora. No item do projeto não há pessoa.
     *
     * @param  array<int, mixed>  $pessoas
     * @return list<string>
     */
    private function pessoasDoItem(Projeto $projeto, ItemConferencia $item, array $pessoas): array
    {
        if ($item->doProjeto()) {
            return [];
        }

        $pessoas = array_values(array_unique(array_map('strval', $pessoas)));
        $validas = array_column(
            array_filter(EquipeProjeto::de($projeto), fn (array $p) => $item->valePara($p['tipo'])),
            'chave',
        );

        if ($pessoas === []) {
            throw ValidationException::withMessages([
                'pessoas' => 'Marque ao menos uma pessoa para quem o arquivo vale.',
            ]);
        }

        if (array_diff($pessoas, $validas) !== []) {
            throw ValidationException::withMessages([
                'pessoas' => 'Marque só pessoas deste projeto a quem o item se aplica.',
            ]);
        }

        return $pessoas;
    }

    /** @param  list<string>  $pessoas */
    private function gravarPessoas(ProjetoDocumento $documento, array $pessoas): void
    {
        $documento->pessoas()->delete();

        foreach ($pessoas as $chave) {
            [$tipo, $id] = explode(':', $chave, 2);
            $documento->pessoas()->create(['pessoa_tipo' => $tipo, 'pessoa_id' => (int) $id]);
        }
    }

    private function garantirItemRecebidoAntes(ItemConferencia $item): void
    {
        if (! $item->ativo || ! $item->receber_antes) {
            abort(404);
        }
    }

    /**
     * Os ids dos projetos deste orientador que estão na lista final vigente.
     *
     * @return Collection<int, int>
     */
    private function finalistas(User $orientador, bool $teste = false): Collection
    {
        $modoTeste = $teste && (bool) $orientador->is_demo;
        $lista = ListaFinal::vigente(Edicao::atual(), $modoTeste);

        if ($lista === null) {
            return collect();
        }

        return $lista->projetos()
            ->where('projetos.user_id', $orientador->id)
            ->pluck('projetos.id');
    }

    private function garantirFinalista(Projeto $projeto, User $autor, bool $teste = false): void
    {
        if (! $this->finalistas($autor, $teste)->contains($projeto->id)) {
            throw ValidationException::withMessages([
                'projeto' => 'Só projetos finalistas enviam documentos para o evento.',
            ]);
        }
    }

    private function ehPdf(ProjetoDocumento $documento): bool
    {
        return $documento->mime === 'application/pdf'
            || str_ends_with(mb_strtolower((string) $documento->nome_original), '.pdf');
    }

    /** @return array<string, mixed> */
    private function formatar(ProjetoDocumento $documento): array
    {
        $laudo = $documento->assinatura ?? [];

        return [
            'id' => $documento->id,
            'nome_original' => $documento->nome_original,
            'tamanho_bytes' => $documento->tamanho_bytes,
            'mime' => $documento->mime,
            'enviado_em' => $documento->created_at?->toIso8601String(),
            'assinatura' => [
                'valida' => $documento->assinatura_valida,
                'assinado' => (bool) ($laudo['assinado'] ?? false),
                'icp_brasil' => (bool) ($laudo['icp_brasil'] ?? false),
                'integro' => $laudo['integro'] ?? null,
                'motivo' => $laudo['motivo'] ?? 'Assinatura não conferida.',
                'signatarios' => array_map(
                    fn (array $s) => [
                        'nome' => $s['nome'] ?? null,
                        // O CPF do certificado aparece mascarado: identifica
                        // sem expor o documento inteiro na tela.
                        'cpf' => isset($s['cpf']) && $s['cpf'] !== null
                            ? '***.'.substr((string) $s['cpf'], 3, 3).'.'.substr((string) $s['cpf'], 6, 3).'-**'
                            : null,
                        'emissor' => $s['emissor'] ?? null,
                    ],
                    (array) ($laudo['signatarios'] ?? []),
                ),
            ],
        ];
    }
}
