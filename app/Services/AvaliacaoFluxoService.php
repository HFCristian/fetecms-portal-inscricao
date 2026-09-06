<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Exceptions\ProjetoJaCobertoException;
use App\Http\Resources\DocumentoResource;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Rubrica;
use Illuminate\Validation\ValidationException;

/**
 * Fluxo de avaliação do avaliador (E7): ler o projeto, iniciar (não pode
 * cancelar), concluir respondendo à rubrica da FETECMS. Depois de enviada, só
 * o **parecer final** (as duas recomendações escritas) ainda pode ser
 * corrigido, com justificativa — a nota é definitiva. O avaliador demo, em
 * modo teste, ignora a data de liberação; suas avaliações são dados de teste
 * (limpáveis pelo admin).
 */
class AvaliacaoFluxoService
{
    public function __construct(
        private readonly FilaAvaliadorService $fila,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /**
     * Pode ler os projetos designados? Depois de liberada, a leitura continua
     * valendo mesmo com o período encerrado — o avaliador ainda consulta o que
     * avaliou. Demo em modo teste ignora as datas.
     */
    public function podeVer(User $user, bool $teste): bool
    {
        if ($user->is_demo && $teste) {
            return true;
        }

        return (bool) Edicao::atual()?->avaliacaoLiberada();
    }

    /**
     * Pode avaliar agora (iniciar, salvar rascunho, enviar)? Exige a liberação
     * e que o período ainda não tenha se encerrado. Demo em modo teste ignora
     * as datas.
     */
    public function podeAvaliar(User $user, bool $teste): bool
    {
        if ($user->is_demo && $teste) {
            return true;
        }

        $edicao = Edicao::atual();

        return (bool) $edicao?->avaliacaoLiberada() && ! $edicao->avaliacaoEncerrada();
    }

    /** Por que a avaliação está fechada agora (para a mensagem de erro/tela). */
    public function motivoBloqueio(): string
    {
        $edicao = Edicao::atual();

        if ($edicao?->avaliacaoEncerrada()) {
            return 'O período de avaliação foi encerrado em '
                .$edicao->avaliacao_encerrada_em->format('d/m/Y H:i')
                .'. Você ainda pode consultar os projetos, mas não é mais possível iniciar, salvar ou enviar avaliações.';
        }

        return 'A avaliação ainda não está liberada.';
    }

    /**
     * Inicia a avaliação (designada → em_andamento). Só uma em andamento por vez.
     *
     * Antes de abrir, confere se o projeto ainda **precisa** desta avaliação:
     * a distribuição designa mais avaliadores do que a cobertura exige (Sprint
     * 106), justamente para não depender de quem não aparece — então quem
     * chega depois do projeto já ter as avaliações da categoria em mãos
     * (concluídas **e** em andamento) não deve começar um trabalho que não vai
     * contar. Nesse caso a designação é devolvida ao bolo, a fila do avaliador
     * é reposta na hora e ele recebe o aviso.
     *
     * A **designação manual do admin** não passa por esta trava: ela é o escape
     * do edital, e quem a fez sabe que está pondo mais um avaliador ali.
     */
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

        if ($this->projetoJaCoberto($avaliacao)) {
            // A troca é gravada antes do aviso: quando a tela recarregar a
            // lista, o projeto já saiu e o substituto já está lá.
            $recebidos = $this->trocarProjetoCoberto($avaliacao);

            throw new ProjetoJaCobertoException(
                'Este projeto já recebeu todas as avaliações necessárias — outro avaliador chegou antes. '
                    .($recebidos > 0
                        ? 'Ele saiu da sua lista e você recebeu outro no lugar.'
                        : 'Ele saiu da sua lista; por ora não há outro projeto disponível para você.'),
                recebeuOutro: $recebidos > 0,
            );
        }

        $avaliacao->update(['status' => StatusAvaliacao::EmAndamento]);
    }

    /**
     * O projeto já tem as avaliações que a categoria dele pede?
     *
     * Conta **concluídas + em andamento**: quem já abriu está ocupando uma das
     * vagas, e considerar só as concluídas deixaria três pessoas trabalhando no
     * mesmo projeto para nada.
     */
    private function projetoJaCoberto(Avaliacao $avaliacao): bool
    {
        // O admin designou à mão: é escape do edital e passa por cima.
        if ($avaliacao->designacao_manual) {
            return false;
        }

        $projeto = $avaliacao->projeto;

        if ($projeto === null) {
            return false;
        }

        $assumidas = Avaliacao::where('projeto_id', $projeto->id)
            ->whereIn('status', [StatusAvaliacao::Concluida->value, StatusAvaliacao::EmAndamento->value])
            ->count();

        return $assumidas >= Edicao::limites()->minPorProjeto($projeto->categoria);
    }

    /**
     * Devolve ao bolo a designação de um projeto que já está coberto e completa
     * a fila do avaliador. Devolve quantas designações novas ele recebeu.
     */
    private function trocarProjetoCoberto(Avaliacao $avaliacao): int
    {
        $avaliador = $avaliacao->avaliador;
        $projetoId = $avaliacao->projeto_id;

        $avaliacao->delete();

        // Sem o dono da avaliação carregado não há fila a repor — não deveria
        // acontecer, mas a troca não pode derrubar o aviso.
        return $avaliador === null ? 0 : $this->fila->repor($avaliador, [$projetoId]);
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
            ...$this->camposPreenchiveis($dados),
            'rascunho_em' => now(),
        ]);
    }

    /**
     * Conclui a avaliação (em_andamento → concluida) com a rubrica preenchida.
     * A nota final é a soma PONDERADA das respostas (0 a 10, pelos pesos do
     * documento) — calculada aqui, nunca enviada pelo cliente.
     *
     * Enviada a avaliação, a fila do avaliador é reposta na hora: sai um projeto
     * da lista de trabalho, entra outro no lugar.
     *
     * @param  array<string, mixed>  $dados  Já validado pelo ConcluirAvaliacaoRequest.
     */
    public function concluir(Avaliacao $avaliacao, array $dados): void
    {
        $this->garantirEmAndamento($avaliacao, 'Inicie a avaliação antes de concluir.');

        $avaliacao->fill($this->camposPreenchiveis($dados));

        $avaliacao->update([
            'status' => StatusAvaliacao::Concluida,
            'nota' => $avaliacao->notaCalculada(),
            // A avaliação foi enviada: não é mais um rascunho.
            'rascunho_em' => null,
            'concluida_em' => now(),
        ]);

        if ($avaliador = $avaliacao->avaliador) {
            $this->fila->repor($avaliador);
        }
    }

    /**
     * Corrige o **parecer final** de uma avaliação já enviada: só as duas
     * recomendações escritas.
     *
     * O envio continua irreversível para tudo que vira nota — as 17 respostas
     * da rubrica e a conferência de área/subárea não se mexem, senão o ranking
     * mudaria depois de fechado. O que se corrige aqui é o texto que o
     * orientador lê na aba Ajustes, e por isso cada campo alterado exige
     * **justificativa** e vira registro em Registros → Avaliação Online.
     *
     * @param  array<string, mixed>  $dados  Já validado pelo EditarParecerRequest.
     * @return list<string> os campos que mudaram
     */
    public function editarParecer(Avaliacao $avaliacao, array $dados, User $avaliador): array
    {
        if ($avaliacao->status !== StatusAvaliacao::Concluida) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Só é possível editar o parecer de uma avaliação já enviada.',
            ]);
        }

        $justificativa = trim((string) $dados['justificativa']);
        $rotulos = [
            'comentario_video' => 'recomendações sobre o vídeo',
            'comentario_projeto' => 'recomendações sobre o projeto',
        ];
        $mudancas = [];

        foreach ($rotulos as $campo => $rotulo) {
            if (! array_key_exists($campo, $dados) || (string) $avaliacao->{$campo} === (string) $dados[$campo]) {
                continue;
            }

            $mudancas[$campo] = $dados[$campo];

            if ($avaliacao->projeto !== null) {
                $this->registros->parecerEditado(
                    $avaliacao->projeto, $avaliador, $rotulo,
                    $avaliacao->{$campo}, $dados[$campo], $justificativa,
                );
            }
        }

        if ($mudancas !== []) {
            $avaliacao->update($mudancas);
        }

        return array_values(array_intersect_key($rotulos, $mudancas));
    }

    private function garantirEmAndamento(Avaliacao $avaliacao, string $mensagem): void
    {
        if ($avaliacao->status !== StatusAvaliacao::EmAndamento) {
            throw ValidationException::withMessages(['avaliacao' => $mensagem]);
        }
    }

    /**
     * Só os campos da rubrica e da classificação, para o preenchimento nunca
     * carregar `status`, `nota` ou qualquer outra chave vinda do cliente. As
     * respostas ainda passam pela normalização do catálogo (chaves conhecidas,
     * inteiro na escala, booleano no Sim/Não).
     *
     * Quando o avaliador marca a classificação como correta, a sugestão
     * correspondente é zerada — assim não sobra sugestão órfã de uma resposta
     * anterior salva em rascunho.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function camposPreenchiveis(array $dados): array
    {
        $permitidos = [...Avaliacao::CAMPOS_CLASSIFICACAO, ...Rubrica::COMENTARIOS, 'respostas'];

        $campos = array_intersect_key($dados, array_flip($permitidos));

        if (array_key_exists('respostas', $campos)) {
            $campos['respostas'] = Rubrica::normalizar((array) $campos['respostas']);
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
            // Continuação de pesquisa anterior: contexto de leitura (o documento,
            // quando existe, vai junto na lista de anexos).
            'continuacao' => (bool) $projeto->continuacao,
            'tempo_pesquisa_meses' => $projeto->tempo_pesquisa_meses,
            'instituicao' => $projeto->instituicao?->nome,
            'alunos' => $projeto->alunos->pluck('nome')->values()->all(),
            'coorientador' => $projeto->coorientador?->nome,
            'documentos' => DocumentoResource::collection($projeto->documentos)->resolve(),
        ];
    }
}
