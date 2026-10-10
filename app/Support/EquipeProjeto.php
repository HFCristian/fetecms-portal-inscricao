<?php

namespace App\Support;

use App\Enums\TipoPessoaCredenciamento;
use App\Models\Projeto;

/**
 * A equipe de um projeto como os balcões a enxergam: estudantes, orientador e
 * coorientador, cada um com o papel e a chave "papel:id" — a mesma das
 * marcações do credenciamento, da checagem e dos arquivos enviados antes.
 */
final class EquipeProjeto
{
    /** @return list<array{chave:string, tipo:string, tipo_label:string, id:int, nome:string}> */
    public static function de(Projeto $projeto): array
    {
        $projeto->loadMissing(['alunos:id,projeto_id,nome', 'user:id,name', 'coorientador']);

        $pessoa = fn (TipoPessoaCredenciamento $t, int $id, string $nome) => [
            'chave' => $t->value.':'.$id, 'tipo' => $t->value, 'tipo_label' => $t->label(), 'id' => $id, 'nome' => $nome,
        ];

        $lista = $projeto->alunos
            ->sortBy(fn ($a) => mb_strtolower((string) $a->nome))
            ->map(fn ($a) => $pessoa(TipoPessoaCredenciamento::Aluno, $a->id, $a->nome))
            ->values()
            ->all();

        if ($projeto->user !== null) {
            $lista[] = $pessoa(TipoPessoaCredenciamento::Orientador, $projeto->user->id, $projeto->user->name);
        }

        if ($projeto->coorientador !== null) {
            $lista[] = $pessoa(TipoPessoaCredenciamento::Coorientador, $projeto->coorientador->id, $projeto->coorientador->nome);
        }

        return $lista;
    }
}
