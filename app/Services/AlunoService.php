<?php

namespace App\Services;

use App\Models\Aluno;
use App\Models\Projeto;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AlunoService
{
    /**
     * Garante que é possível adicionar mais um aluno ao projeto: exige categoria
     * definida e respeita o limite por categoria (Jr=3, FUNDECT=4, FETECMS=3 ou
     * 4 com PICTEC MS).
     */
    public function assertPodeAdicionar(Projeto $projeto): void
    {
        if ($projeto->categoria === null) {
            throw ValidationException::withMessages([
                'categoria' => 'Defina a categoria do projeto antes de adicionar alunos.',
            ]);
        }

        $max = $projeto->maxAlunos();
        if ($projeto->alunos()->count() >= $max) {
            throw ValidationException::withMessages([
                'equipe' => "Limite de {$max} alunos para a categoria {$projeto->categoria->label()} atingido.",
            ]);
        }
    }

    public function adicionar(Projeto $projeto, array $data): Aluno
    {
        // Serializa adições concorrentes ao mesmo projeto (lockForUpdate vira
        // SELECT ... FOR UPDATE no Postgres de produção) para que o limite por
        // categoria não seja burlado por uma corrida entre duas requisições.
        return DB::transaction(function () use ($projeto, $data) {
            Projeto::whereKey($projeto->getKey())->lockForUpdate()->first();
            $this->assertPodeAdicionar($projeto);

            return $projeto->alunos()->create($data);
        });
    }

    public function atualizar(Aluno $aluno, array $data): Aluno
    {
        $aluno->update($data);

        return $aluno->fresh();
    }
}
