<?php

namespace App\Services;

use App\Models\ItemConferencia;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A lista única de itens do credenciamento e da checagem (Sprint 181):
 * cadastro, edição e exclusão na Parametrização.
 *
 * Um item precisa ser conferido em algum lugar — credenciamento, checagem ou
 * os dois — e, quando é de pessoa, de ao menos um papel. Item já conferido ou
 * com arquivo enviado não é excluído (a conferência ficaria sem o nome do que
 * foi conferido): desativa-se. Os itens do sistema mudam de nome e de lugar,
 * mas não de abrangência, não deixam de ser recebidos antes e não se excluem.
 */
class ItensConferenciaService
{
    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return ItemConferencia::query()
            ->withCount(['credenciamentos', 'checagens', 'arquivos'])
            ->orderBy('ordem')
            ->orderBy('id')
            ->get()
            ->map(fn (ItemConferencia $i) => $this->formatar($i))
            ->all();
    }

    /** @param  array<string, mixed>  $dados */
    public function criar(array $dados): ItemConferencia
    {
        $dados = $this->normalizar($dados);
        $this->garantirNomeLivre($dados['nome']);

        return ItemConferencia::create($dados + [
            'ordem' => (int) ItemConferencia::max('ordem') + 1,
            'ativo' => true,
        ]);
    }

    /** @param  array<string, mixed>  $dados */
    public function atualizar(ItemConferencia $item, array $dados): ItemConferencia
    {
        $dados = $this->normalizar($dados + [
            'nome' => $item->nome,
            'descricao' => $item->descricao,
            'abrangencia' => $item->abrangencia,
            'papeis' => $item->papeis,
            'no_credenciamento' => $item->no_credenciamento,
            'na_checagem' => $item->na_checagem,
            'receber_antes' => $item->receber_antes,
        ]);
        $this->garantirNomeLivre($dados['nome'], $item->id);

        if ($item->doSistema()) {
            // O que o portal sabe sobre o item do sistema não muda.
            $dados['abrangencia'] = $item->abrangencia;
            $dados['papeis'] = $item->papeis;
            $dados['receber_antes'] = true;
        }

        $item->update($dados);

        return $item->fresh();
    }

    public function excluir(ItemConferencia $item): void
    {
        if ($item->doSistema()) {
            throw ValidationException::withMessages([
                'item' => 'Este item é do sistema e não pode ser excluído. Desative-o se não quiser conferi-lo.',
            ]);
        }

        if ($item->credenciamentos()->exists() || $item->checagens()->exists() || $item->arquivos()->exists()) {
            throw ValidationException::withMessages([
                'item' => 'Este item já foi conferido ou recebeu arquivo. Desative-o em vez de excluir.',
            ]);
        }

        $item->delete();
    }

    /** @return array<string, mixed> */
    public function formatar(ItemConferencia $item): array
    {
        return [
            'id' => $item->id,
            'nome' => $item->nome,
            'descricao' => $item->descricao,
            'abrangencia' => $item->abrangencia,
            'papeis' => $item->papeis ?? [],
            'no_credenciamento' => $item->no_credenciamento,
            'na_checagem' => $item->na_checagem,
            'receber_antes' => $item->receber_antes,
            'chave' => $item->chave,
            'ordem' => $item->ordem,
            'ativo' => $item->ativo,
            'em_uso' => (bool) (($item->credenciamentos_count ?? 0) + ($item->checagens_count ?? 0) + ($item->arquivos_count ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function normalizar(array $dados): array
    {
        $projeto = ($dados['abrangencia'] ?? ItemConferencia::PESSOA) === ItemConferencia::PROJETO;
        $papeis = $projeto ? null : array_values(array_unique($dados['papeis'] ?? []));

        if (! $projeto && $papeis === []) {
            throw ValidationException::withMessages([
                'papeis' => 'Marque de quem o item é conferido: aluno, orientador ou coorientador.',
            ]);
        }

        if (! ($dados['no_credenciamento'] ?? false) && ! ($dados['na_checagem'] ?? false)) {
            throw ValidationException::withMessages([
                'onde' => 'Marque onde o item é conferido: no credenciamento, na checagem ou nos dois.',
            ]);
        }

        return array_filter([
            'nome' => Str::squish((string) $dados['nome']),
            'descricao' => array_key_exists('descricao', $dados) ? (trim((string) $dados['descricao']) ?: null) : null,
            'abrangencia' => $projeto ? ItemConferencia::PROJETO : ItemConferencia::PESSOA,
            'papeis' => $papeis,
            'no_credenciamento' => (bool) ($dados['no_credenciamento'] ?? false),
            'na_checagem' => (bool) ($dados['na_checagem'] ?? false),
            'receber_antes' => (bool) ($dados['receber_antes'] ?? false),
            'ordem' => $dados['ordem'] ?? null,
            'ativo' => $dados['ativo'] ?? null,
        ], fn ($v, $k) => $v !== null || in_array($k, ['descricao', 'papeis'], true), ARRAY_FILTER_USE_BOTH);
    }

    private function garantirNomeLivre(string $nome, ?int $ignorar = null): void
    {
        $chave = Str::lower(Str::ascii($nome));

        $repetido = ItemConferencia::query()
            ->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar))
            ->get(['id', 'nome'])
            ->contains(fn (ItemConferencia $i) => Str::lower(Str::ascii($i->nome)) === $chave);

        if ($repetido) {
            throw ValidationException::withMessages(['nome' => 'Já existe um item com este nome.']);
        }
    }
}
