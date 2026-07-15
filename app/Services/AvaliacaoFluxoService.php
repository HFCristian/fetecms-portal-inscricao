<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Http\Resources\DocumentoResource;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Fluxo de avaliação do avaliador (E7): ler o projeto, iniciar (não pode
 * cancelar), concluir com nota 1–10. O avaliador demo, em modo teste, ignora a
 * data de liberação; suas avaliações são dados de teste (limpáveis pelo admin).
 */
class AvaliacaoFluxoService
{
    /** Pode avaliar agora? Demo em modo teste ignora a data; senão, exige liberação. */
    public function podeAvaliar(User $user, bool $teste): bool
    {
        if ($user->is_demo && $teste) {
            return true;
        }

        return (bool) Edicao::atual()?->avaliacaoLiberada();
    }

    /** Inicia a avaliação (designada → em_andamento). Só uma em andamento por vez. */
    public function iniciar(Avaliacao $avaliacao): void
    {
        if ($avaliacao->status === StatusAvaliacao::Concluida) {
            throw ValidationException::withMessages(['avaliacao' => 'Esta avaliação já foi concluída.']);
        }

        if ($avaliacao->status === StatusAvaliacao::EmAndamento) {
            return; // idempotente
        }

        $emAndamento = Avaliacao::where('avaliador_id', $avaliacao->avaliador_id)
            ->where('status', StatusAvaliacao::EmAndamento->value)
            ->exists();

        if ($emAndamento) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Conclua a avaliação em andamento antes de iniciar outra.',
            ]);
        }

        $avaliacao->update(['status' => StatusAvaliacao::EmAndamento]);
    }

    /** Conclui a avaliação (em_andamento → concluida) com a nota. */
    public function concluir(Avaliacao $avaliacao, int $nota): void
    {
        if ($avaliacao->status !== StatusAvaliacao::EmAndamento) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Inicie a avaliação antes de concluir.',
            ]);
        }

        $avaliacao->update(['status' => StatusAvaliacao::Concluida, 'nota' => $nota]);
    }

    /** Conteúdo do projeto para leitura do avaliador. */
    public function detalhesProjeto(Projeto $projeto): array
    {
        $projeto->loadMissing(['area:id,nome', 'subarea:id,nome', 'instituicao:id,nome', 'alunos', 'coorientador', 'documentos']);

        return [
            'id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'categoria' => $projeto->categoria?->label(),
            'area' => $projeto->area?->nome,
            'subarea' => $projeto->subarea?->nome,
            'resumo' => $projeto->resumo,
            'palavras_chave' => $projeto->palavras_chave ?? [],
            'link_video' => $projeto->link_video,
            'instituicao' => $projeto->instituicao?->nome,
            'alunos' => $projeto->alunos->pluck('nome')->values()->all(),
            'coorientador' => $projeto->coorientador?->nome,
            'documentos' => DocumentoResource::collection($projeto->documentos)->resolve(),
        ];
    }
}
