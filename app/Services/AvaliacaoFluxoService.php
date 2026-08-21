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
 * cancelar), concluir com a rubrica em escala Likert. O avaliador demo, em modo
 * teste, ignora a data de liberação; suas avaliações são dados de teste
 * (limpáveis pelo admin).
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

    /**
     * Salva o preenchimento parcial sem enviar: a avaliação segue em_andamento e
     * o avaliador pode voltar depois. Nada é obrigatório aqui — a validação
     * completa só acontece ao concluir.
     *
     * @param  array<string, mixed>  $dados  Já validado pelo RascunhoAvaliacaoRequest.
     */
    public function salvarRascunho(Avaliacao $avaliacao, array $dados): void
    {
        $this->garantirEmAndamento($avaliacao, 'Inicie a avaliação antes de salvar o rascunho.');

        $avaliacao->update([
            ...$this->camposPreenchiveis($dados, $avaliacao),
            'rascunho_em' => now(),
        ]);
    }

    /**
     * Conclui a avaliação (em_andamento → concluida) com a rubrica preenchida.
     * A nota final é a SOMA dos três quesitos (3 a 15) — calculada aqui, nunca
     * enviada pelo cliente. Havendo projeto de continuação, o quesito de
     * pesquisa entra pela média com ele.
     *
     * @param  array<string, mixed>  $dados  Já validado pelo ConcluirAvaliacaoRequest.
     */
    public function concluir(Avaliacao $avaliacao, array $dados): void
    {
        $this->garantirEmAndamento($avaliacao, 'Inicie a avaliação antes de concluir.');

        $avaliacao->fill($this->camposPreenchiveis($dados, $avaliacao));

        $avaliacao->update([
            'status' => StatusAvaliacao::Concluida,
            'nota' => $avaliacao->somaDosQuesitos(),
            // A avaliação foi enviada: não é mais um rascunho.
            'rascunho_em' => null,
            'concluida_em' => now(),
        ]);
    }

    private function garantirEmAndamento(Avaliacao $avaliacao, string $mensagem): void
    {
        if ($avaliacao->status !== StatusAvaliacao::EmAndamento) {
            throw ValidationException::withMessages(['avaliacao' => $mensagem]);
        }
    }

    /**
     * Só os campos da rubrica e da classificação, para o preenchimento nunca
     * carregar `status`, `nota` ou qualquer outra chave vinda do cliente.
     *
     * Quando o avaliador marca a classificação como correta, a sugestão
     * correspondente é zerada — assim não sobra sugestão órfã de uma resposta
     * anterior salva em rascunho.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function camposPreenchiveis(array $dados, Avaliacao $avaliacao): array
    {
        $permitidos = [...Avaliacao::CAMPOS_CLASSIFICACAO];
        $quesitos = [...Avaliacao::QUESITOS, Avaliacao::QUESITO_CONTINUIDADE];

        foreach ($quesitos as $quesito) {
            $permitidos[] = "nota_{$quesito}";
            $permitidos[] = "comentario_{$quesito}";
        }

        $campos = array_intersect_key($dados, array_flip($permitidos));

        // Projeto sem documento de continuação não tem esse quesito: o que vier
        // do cliente é descartado e as colunas ficam nulas (inclusive se um
        // rascunho anterior chegou a gravá-las).
        if (! $avaliacao->projeto?->temProjetoDeContinuacao()) {
            $campos['nota_continuidade'] = null;
            $campos['comentario_continuidade'] = null;
        }

        if (($campos['area_correta'] ?? null) === true) {
            $campos['area_sugerida_id'] = null;
        }

        if (($campos['subarea_correta'] ?? null) === true) {
            $campos['subarea_sugerida_id'] = null;
        }

        return $campos;
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
            // Ids para o avaliador conferir a classificação (sugerir área/subárea).
            'area_id' => $projeto->area_id,
            'subarea_id' => $projeto->subarea_id,
            'resumo' => $projeto->resumo,
            'palavras_chave' => $projeto->palavras_chave ?? [],
            'link_video' => $projeto->link_video,
            // Projeto de continuação: rende um quesito a mais na rubrica.
            'continuacao' => (bool) $projeto->continuacao,
            'tempo_pesquisa_meses' => $projeto->tempo_pesquisa_meses,
            'avalia_continuidade' => $projeto->temProjetoDeContinuacao(),
            'instituicao' => $projeto->instituicao?->nome,
            'alunos' => $projeto->alunos->pluck('nome')->values()->all(),
            'coorientador' => $projeto->coorientador?->nome,
            'documentos' => DocumentoResource::collection($projeto->documentos)->resolve(),
        ];
    }
}
