<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Enums\VagaPresencial;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Support\JanelaTurnos;
use App\Support\RubricaPresencial;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ranking presencial → **Ver avaliações** (Sprint 237): as avaliações do
 * estande de um projeto, com quem avaliou, a vaga, a nota e os pontos de cada
 * seção da rubrica — e a **exclusão** de uma delas.
 *
 * O ranking mostra as notas sem nome; aqui o admin vê de quem é cada uma, que é
 * o que ele precisa para decidir tirar uma. Por isso abrir fica registrado
 * (Registros → Notas), uma linha por nota que estava na tela, como no Ver notas
 * da avaliação online.
 *
 * Entram as **enviadas** e as **em andamento** (inclusive a que venceu o prazo
 * sem envio): as duas ocupam ou ocuparam vaga, e as duas podem sair. A só
 * designada fica na Distribuição presencial, que é onde ela se retira.
 *
 * Excluir apaga a linha: a vaga volta a abrir no estande, o projeto não é
 * reavaliado na hora e o mesmo avaliador pode avaliá-lo de novo. A
 * justificativa é obrigatória e o registro guarda o que a avaliação era, porque
 * depois dele não sobra mais nada dela.
 */
class NotasPresenciaisService
{
    public function __construct(
        private readonly RegistroAtividadeService $registros,
        private readonly ListaFinalService $listas,
    ) {}

    /**
     * As avaliações de um finalista. `$registrar` é falso na tela devolvida
     * depois de uma exclusão: aquele ato já tem o registro dele.
     *
     * @return array<string, mixed>
     */
    public function doProjeto(Projeto $projeto, User $admin, bool $demo, bool $registrar = true): array
    {
        $lista = $this->listaDoProjeto($projeto, $demo);
        $janela = JanelaTurnos::daEdicao();
        $projeto->loadMissing('area:id,nome');

        $avaliacoes = AvaliacaoPresencial::where('projeto_id', $projeto->id)
            ->whereIn('status', [StatusAvaliacao::Concluida->value, StatusAvaliacao::EmAndamento->value])
            ->with('avaliador:id,name')
            // As enviadas primeiro, na ordem em que chegaram; as abertas no fim.
            ->orderByRaw("CASE status WHEN 'concluida' THEN 0 ELSE 1 END")
            ->orderBy('concluida_em')
            ->orderBy('id')
            ->get();

        $soma = 0.0;
        $pesos = 0.0;

        $linhas = $avaliacoes->map(function (AvaliacaoPresencial $a) use ($projeto, $admin, $registrar, $janela, &$soma, &$pesos) {
            $vaga = $a->vaga ?? VagaPresencial::Comum;
            $peso = $janela->peso($vaga);
            $concluida = $a->concluida();
            $nome = $a->avaliador?->name ?? 'Avaliador #'.$a->avaliador_id;

            if ($concluida && $a->nota !== null) {
                $soma += $a->nota * $peso;
                $pesos += $peso;

                if ($registrar) {
                    $this->registros->notaPresencialVisualizada($projeto, $admin, $nome, $a->nota);
                }
            }

            $anterior = RubricaPresencial::deOutraRubrica($a->respostas);

            return [
                'avaliacao_id' => $a->id,
                'avaliador' => $nome,
                'status' => $a->status?->value,
                'status_label' => $a->expirada($janela) ? 'Prazo encerrado' : $a->status?->label(),
                'vaga' => $vaga->value,
                'vaga_label' => $vaga->label(),
                'peso' => $peso,
                'nota' => $concluida ? $a->nota : null,
                'ocorrencia_label' => $a->dia === null || $a->turno === null
                    ? null
                    : $a->dia->format('d/m').' · '.$a->turno->label(),
                'origem_label' => $a->escolha_livre ? 'Escolhido no estande' : 'Designado',
                'iniciada_em_label' => $a->iniciada_em?->format('d/m/Y H:i'),
                'concluida_em_label' => $a->concluida_em?->format('d/m/Y H:i'),
                'rubrica_anterior' => $anterior,
                // Só os pontos por seção, e só de quem enviou: o rascunho é
                // resposta pela metade, e pô-lo ao lado de uma nota seria
                // comparar coisas diferentes.
                'secoes' => $concluida && ! $anterior
                    ? collect(self::secoes())
                        ->mapWithKeys(fn (array $s) => [$s['chave'] => RubricaPresencial::pontosDaSecao($s['chave'], $a->respostas ?? [])])
                        ->all()
                    : null,
                'comentario' => $concluida ? $a->comentario : null,
            ];
        })->all();

        $estande = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)->where('projeto_id', $projeto->id)->first();

        return [
            'projeto' => [
                'id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'codigo' => $this->listas->codigosDaLista($lista)[$projeto->id] ?? null,
                'area' => $projeto->area?->nome,
                'categoria' => $projeto->categoria?->label(),
                'estande' => $estande?->numero,
                'turno_label' => $estande?->turno?->label(),
            ],
            // A mesma média do ranking: Σ (nota × peso) / Σ peso.
            'media' => $pesos > 0 ? round($soma / $pesos, RubricaPresencial::CASAS) : null,
            'nota_maxima' => RubricaPresencial::NOTA_MAXIMA,
            'secoes' => array_map(fn (array $s) => [
                'chave' => $s['chave'],
                'titulo' => $s['titulo'],
                'maximo' => RubricaPresencial::maximoDaSecao($s['chave']),
            ], self::secoes()),
            'avaliacoes' => $linhas,
        ];
    }

    /**
     * Exclui uma avaliação presencial e devolve a tela do projeto sem ela.
     *
     * @return array<string, mixed>
     */
    public function excluir(AvaliacaoPresencial $avaliacao, User $admin, string $justificativa, bool $demo): array
    {
        $projeto = $avaliacao->projeto;
        abort_if($projeto === null, 404);
        $this->listaDoProjeto($projeto, $demo);

        if (! in_array($avaliacao->status, [StatusAvaliacao::Concluida, StatusAvaliacao::EmAndamento], true)) {
            throw ValidationException::withMessages([
                'avaliacao' => 'Esta avaliação ainda não foi aberta — a designação é retirada na Distribuição presencial.',
            ]);
        }

        $this->apagar($avaliacao, $admin, $justificativa);

        return $this->doProjeto($projeto, $admin, $demo, registrar: false);
    }

    /**
     * Apaga a avaliação (enviada ou em andamento) com o registro do que ela
     * era. Também é o caminho do acompanhamento do comitê (Sprint 238).
     */
    public function apagar(AvaliacaoPresencial $avaliacao, User $admin, string $justificativa): void
    {
        $avaliacao->loadMissing(['avaliador:id,name', 'projeto']);
        abort_if($avaliacao->projeto === null, 404);

        // O registro primeiro: ele precisa do que a linha tem, e a linha some.
        DB::transaction(function () use ($avaliacao, $admin, $justificativa) {
            $this->registros->avaliacaoPresencialExcluida(
                $avaliacao->projeto,
                $admin,
                $avaliacao->avaliador?->name ?? 'Avaliador #'.$avaliacao->avaliador_id,
                $avaliacao->concluida() ? $avaliacao->nota : null,
                $avaliacao->concluida() ? 'enviada' : 'em andamento',
                ($avaliacao->vaga ?? VagaPresencial::Comum)->label(),
                $justificativa,
            );

            $avaliacao->delete();
        });
    }

    /** As seções que valem ponto, na ordem do documento. */
    private static function secoes(): array
    {
        return array_values(array_filter(RubricaPresencial::SECOES, fn (array $s) => $s['perguntas'] !== []));
    }

    /**
     * A lista que vale para o projeto: a oficial, ou a demo no modo de teste.
     * Fora dela, o projeto não é finalista aqui — e o modo de teste nunca
     * alcança um finalista de verdade. Também é a trava da transferência
     * (Sprint 249): o destino tem de estar na mesma lista.
     */
    public function listaDoProjeto(Projeto $projeto, bool $demo): ListaFinal
    {
        $lista = ListaFinal::vigente(Edicao::atual(), $demo);

        abort_if($lista === null || ! $lista->projetos()->where('projetos.id', $projeto->id)->exists(), 404);

        return $lista;
    }
}
