<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\RubricaPresencial;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação presencial — **lado do avaliador**.
 *
 * Quem avalia no estande é quem **aceitou** avaliar presencialmente (aba
 * Presencial, Sprint 131) e só **durante o evento**: fora da janela a aba abre
 * em leitura, com o que ele já respondeu.
 *
 * O que ele avalia chega por dois caminhos, e os dois existem de propósito:
 *
 * - o **admin designa** antes, distribuindo os estandes entre quem confirmou;
 * - o **avaliador escolhe** no local, porque no corredor a realidade muda —
 *   uma equipe saiu para almoçar, outra ainda não montou, e mandar alguém
 *   esperar um estande vazio desperdiça o único dia que a feira tem.
 *
 * O que os dois respeitam é o **teto de 3 avaliações por projeto**
 * (`AvaliacaoPresencial::MAX_POR_PROJETO`): batido o número, o projeto sai da
 * lista dos disponíveis e quem tentar abrir recebe o aviso de que outro chegou
 * antes. O tempo do evento é curto, e um quarto parecer num projeto já visitado
 * é um estande sem visita nenhuma em algum outro corredor.
 *
 * A nota é **separada** da avaliação online: ela não mexe no ranking que
 * definiu a lista final — alimenta a premiação.
 */
class AvaliacaoPresencialFluxoService
{
    public function __construct(private readonly AvaliacaoPresencialService $intencao) {}

    /** O avaliador pode **escrever** (iniciar, salvar, concluir) agora? */
    public function podeAvaliar(User $avaliador, bool $teste = false): bool
    {
        $edicao = Edicao::atual();
        $modoTeste = $teste && (bool) $avaliador->is_demo;

        return $this->intencao->aceitou($avaliador)
            && ($modoTeste || (bool) $edicao?->eventoEmAndamento())
            && ListaFinal::vigente($edicao, $modoTeste) !== null;
    }

    /** Motivo de a avaliação estar fechada, em palavras. */
    public function motivoFechado(User $avaliador, bool $teste = false): string
    {
        if (! $this->intencao->aceitou($avaliador)) {
            return 'Marque que quer avaliar presencialmente para receber os projetos do dia.';
        }

        $edicao = Edicao::atual();

        if (ListaFinal::vigente($edicao, $teste && $avaliador->is_demo) === null) {
            return 'A lista final ainda não foi publicada.';
        }

        if ($edicao?->evento_de === null) {
            return 'As datas do evento ainda não foram definidas.';
        }

        return $edicao->eventoEncerrado()
            ? 'O evento terminou: as suas avaliações ficam disponíveis para consulta.'
            : 'A avaliação presencial abre no início do evento.';
    }

    /**
     * O painel do avaliador presencial: o que está com ele e o que ainda pode
     * pegar.
     *
     * @return array<string, mixed>
     */
    public function painel(User $avaliador, bool $teste = false): array
    {
        $modoTeste = $teste && (bool) $avaliador->is_demo;
        $edicao = Edicao::atual();
        $pode = $this->podeAvaliar($avaliador, $teste);

        $minhas = AvaliacaoPresencial::where('avaliador_id', $avaliador->id)
            ->with(['projeto.area:id,nome', 'projeto.instituicao:id,nome', 'projeto.alunos:id,projeto_id,nome'])
            ->orderByRaw("CASE status WHEN 'em_andamento' THEN 0 WHEN 'designada' THEN 1 ELSE 2 END")
            ->get();

        return [
            'aberto' => $pode,
            'motivo_fechado' => $pode ? null : $this->motivoFechado($avaliador, $teste),
            'aceitou' => $this->intencao->aceitou($avaliador),
            'modo_teste' => $modoTeste,
            'is_demo' => (bool) $avaliador->is_demo,
            'evento_de_label' => $edicao?->evento_de?->format('d/m/Y H:i'),
            'evento_ate_label' => $edicao?->evento_ate?->format('d/m/Y H:i'),
            'rubrica' => RubricaPresencial::paraApi(),
            'max_por_projeto' => AvaliacaoPresencial::MAX_POR_PROJETO,
            'minhas' => $minhas->map(fn (AvaliacaoPresencial $a) => $this->linha($a))->all(),
            // Estandes que ainda cabem mais um avaliador.
            'disponiveis' => $pode ? $this->disponiveis($avaliador, $modoTeste) : [],
        ];
    }

    /**
     * Abre (ou retoma) a avaliação de um projeto.
     *
     * @return array<string, mixed>
     */
    public function iniciar(User $avaliador, Projeto $projeto, bool $teste = false): array
    {
        $this->garantirAberto($avaliador, $teste);
        $this->garantirFinalista($projeto, $avaliador, $teste);

        $avaliacao = AvaliacaoPresencial::where('projeto_id', $projeto->id)
            ->where('avaliador_id', $avaliador->id)
            ->first();

        if ($avaliacao?->concluida()) {
            throw ValidationException::withMessages([
                'projeto' => 'Você já avaliou este projeto.',
            ]);
        }

        // A disputa se resolve aqui: quem chega depois do teto é avisado, e o
        // projeto sai da lista dele.
        if ($avaliacao === null && ! $this->cabeMaisUm($projeto)) {
            throw ValidationException::withMessages([
                'projeto' => 'Este projeto já recebeu o número máximo de avaliações presenciais.',
            ]);
        }

        $avaliacao ??= new AvaliacaoPresencial([
            'edicao_id' => Edicao::atual()?->id,
            'projeto_id' => $projeto->id,
            'avaliador_id' => $avaliador->id,
            'designacao_manual' => false,
        ]);

        if ($avaliacao->status !== StatusAvaliacao::EmAndamento) {
            $avaliacao->status = StatusAvaliacao::EmAndamento;
            $avaliacao->iniciada_em ??= now();
        }

        $avaliacao->save();

        return $this->detalhe($avaliacao->fresh());
    }

    /**
     * Salva o rascunho das respostas (o avaliador anda pelo estande e volta).
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function salvarRascunho(User $avaliador, AvaliacaoPresencial $avaliacao, array $dados, bool $teste = false): array
    {
        $this->garantirAberto($avaliador, $teste);
        $this->garantirDono($avaliador, $avaliacao);

        if ($avaliacao->concluida()) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta avaliação já foi enviada.',
            ]);
        }

        $avaliacao->forceFill([
            'status' => StatusAvaliacao::EmAndamento,
            'iniciada_em' => $avaliacao->iniciada_em ?? now(),
            'respostas' => $this->limparRespostas($dados['respostas'] ?? []),
            'comentario' => $dados['comentario'] ?? null,
        ])->save();

        return $this->detalhe($avaliacao->fresh());
    }

    /**
     * Envia a avaliação: calcula a nota no servidor e fecha.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function concluir(User $avaliador, AvaliacaoPresencial $avaliacao, array $dados, bool $teste = false): array
    {
        $this->garantirAberto($avaliador, $teste);
        $this->garantirDono($avaliador, $avaliacao);

        if ($avaliacao->concluida()) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta avaliação já foi enviada.',
            ]);
        }

        $respostas = $this->limparRespostas($dados['respostas'] ?? []);
        $faltando = array_diff(RubricaPresencial::chaves(), array_keys($respostas));

        if ($faltando !== []) {
            throw ValidationException::withMessages([
                'respostas' => 'Responda todas as perguntas antes de enviar.',
            ]);
        }

        DB::transaction(function () use ($avaliacao, $respostas, $dados) {
            $avaliacao->forceFill([
                'respostas' => $respostas,
                'comentario' => $dados['comentario'] ?? null,
                // A nota é calculada no servidor: o front só desenha a rubrica.
                'nota' => RubricaPresencial::nota($respostas),
                'status' => StatusAvaliacao::Concluida,
                'concluida_em' => now(),
            ])->save();
        });

        return $this->detalhe($avaliacao->fresh());
    }

    /**
     * Uma avaliação aberta para responder (ou reler).
     *
     * @return array<string, mixed>
     */
    public function detalhe(AvaliacaoPresencial $avaliacao): array
    {
        $avaliacao->loadMissing([
            'projeto.area:id,nome', 'projeto.subarea:id,nome', 'projeto.instituicao:id,nome',
            'projeto.alunos:id,projeto_id,nome', 'projeto.user:id,name',
        ]);

        $projeto = $avaliacao->projeto;

        return [
            'id' => $avaliacao->id,
            'status' => $avaliacao->status?->value,
            'status_label' => $avaliacao->status?->label(),
            'respostas' => $avaliacao->respostas ?? [],
            'comentario' => $avaliacao->comentario,
            'nota' => $avaliacao->nota,
            'nota_maxima' => RubricaPresencial::NOTA_MAXIMA,
            'concluida_em' => $avaliacao->concluida_em?->toIso8601String(),
            'projeto' => [
                'id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'resumo' => $projeto->resumo,
                'categoria' => $projeto->categoria?->label(),
                'area' => $projeto->area?->nome,
                'subarea' => $projeto->subarea?->nome,
                'escola' => $projeto->instituicao?->nome,
                'orientador' => $projeto->user?->name,
                'alunos' => $projeto->alunos->pluck('nome')->all(),
                'local' => $this->local($projeto->id),
            ],
        ];
    }

    // --- Internos ----------------------------------------------------------

    /**
     * Os finalistas que ainda cabem mais um avaliador e que este ainda não
     * pegou.
     *
     * @return list<array<string, mixed>>
     */
    private function disponiveis(User $avaliador, bool $demo): array
    {
        $lista = ListaFinal::vigente(Edicao::atual(), $demo);

        if ($lista === null) {
            return [];
        }

        $jaComigo = AvaliacaoPresencial::where('avaliador_id', $avaliador->id)->pluck('projeto_id')->all();

        // Contagem por projeto: concluídas + em andamento ocupam vaga, porque
        // quem abriu está no estande agora.
        $ocupadas = AvaliacaoPresencial::whereIn('status', [
            StatusAvaliacao::Concluida->value,
            StatusAvaliacao::EmAndamento->value,
        ])->selectRaw('projeto_id, COUNT(*) as total')
            ->groupBy('projeto_id')
            ->pluck('total', 'projeto_id');

        return Projeto::whereIn('id', $lista->projetos()->select('projetos.id'))
            ->whereNotIn('id', $jaComigo)
            ->with(['area:id,nome', 'instituicao:id,nome'])
            ->orderBy('titulo')
            ->get()
            ->filter(fn (Projeto $p) => (int) ($ocupadas[$p->id] ?? 0) < AvaliacaoPresencial::MAX_POR_PROJETO)
            ->map(fn (Projeto $p) => [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'categoria' => $p->categoria?->label(),
                'area' => $p->area?->nome,
                'escola' => $p->instituicao?->nome,
                'local' => $this->local($p->id),
                'avaliacoes' => (int) ($ocupadas[$p->id] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function cabeMaisUm(Projeto $projeto): bool
    {
        $ocupadas = AvaliacaoPresencial::where('projeto_id', $projeto->id)
            ->whereIn('status', [StatusAvaliacao::Concluida->value, StatusAvaliacao::EmAndamento->value])
            ->count();

        return $ocupadas < AvaliacaoPresencial::MAX_POR_PROJETO;
    }

    /** @return array<string, mixed> */
    private function linha(AvaliacaoPresencial $avaliacao): array
    {
        $projeto = $avaliacao->projeto;

        return [
            'id' => $avaliacao->id,
            'projeto_id' => $projeto?->id,
            'titulo' => $projeto?->titulo,
            'area' => $projeto?->area?->nome,
            'escola' => $projeto?->instituicao?->nome,
            'local' => $projeto === null ? null : $this->local($projeto->id),
            'status' => $avaliacao->status?->value,
            'status_label' => $avaliacao->status?->label(),
            'designacao_manual' => $avaliacao->designacao_manual,
            'nota' => $avaliacao->nota,
            'nota_maxima' => RubricaPresencial::NOTA_MAXIMA,
            'concluida_em' => $avaliacao->concluida_em?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function local(int $projetoId): array
    {
        $estande = EstandeProjeto::where('projeto_id', $projetoId)->first();
        $turno = TurnoApresentacao::where('projeto_id', $projetoId)->first();

        return [
            'estande' => $estande?->numero,
            'turno_label' => $turno?->turno?->label() ?? $estande?->turno?->label(),
        ];
    }

    /**
     * Só o que a rubrica conhece, e só valores da escala.
     *
     * @param  array<string, mixed>  $respostas
     * @return array<string, int>
     */
    private function limparRespostas(array $respostas): array
    {
        $validas = RubricaPresencial::chaves();
        $escala = array_keys(RubricaPresencial::ESCALA);
        $limpas = [];

        foreach ($respostas as $chave => $valor) {
            if (in_array($chave, $validas, true) && in_array((int) $valor, $escala, true)) {
                $limpas[$chave] = (int) $valor;
            }
        }

        return $limpas;
    }

    private function garantirAberto(User $avaliador, bool $teste): void
    {
        if (! $this->podeAvaliar($avaliador, $teste)) {
            throw ValidationException::withMessages([
                'periodo' => $this->motivoFechado($avaliador, $teste),
            ]);
        }
    }

    private function garantirDono(User $avaliador, AvaliacaoPresencial $avaliacao): void
    {
        abort_unless($avaliacao->avaliador_id === $avaliador->id, 403);
    }

    private function garantirFinalista(Projeto $projeto, User $avaliador, bool $teste): void
    {
        $lista = ListaFinal::vigente(Edicao::atual(), $teste && (bool) $avaliador->is_demo);

        if ($lista === null || ! $lista->projetos()->whereKey($projeto->id)->exists()) {
            abort(404);
        }
    }
}
