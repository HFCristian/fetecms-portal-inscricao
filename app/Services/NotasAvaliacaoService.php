<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Rubrica;

/**
 * A nota de uma avaliação **aberta por dentro**: seção por seção da rubrica
 * oficial, cada pergunta com o rótulo que o avaliador leu e os pontos que ela
 * rendeu.
 *
 * Mora aqui, e não no {@see DesignacaoService}, porque duas telas precisam da
 * mesma leitura por caminhos diferentes:
 *
 * - **Designações → Ver notas** abre a avaliação de uma pessoa;
 * - **Verificar disparidade → Ver notas** põe *todas* as avaliações do mesmo
 *   projeto lado a lado, que é o que responde "em que eles discordaram?" — a
 *   nota final diz que a distância existe, a seção diz onde ela nasceu.
 *
 * As duas ficam registradas em Registros → Notas: a consulta não muda nada, mas
 * a nota decide a lista final e o parecer é anônimo para o orientador, então
 * quem lê fica rastreável.
 */
class NotasAvaliacaoService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /**
     * As seções pontuadas de uma avaliação, com as perguntas de cada uma.
     *
     * @return list<array<string, mixed>>
     */
    public function secoes(Avaliacao $avaliacao): array
    {
        $respostas = $avaliacao->respostas ?? [];

        return array_map(function (array $secao) use ($respostas) {
            $perguntas = array_values(array_filter(
                Rubrica::perguntas(),
                fn (array $p) => $p['secao'] === $secao['chave'],
            ));

            return [
                'chave' => $secao['chave'],
                'titulo' => $secao['titulo'],
                'pontos' => round(Rubrica::pontosDaSecao($secao['chave'], $respostas), 2),
                'maximo' => round($secao['maximo'], 2),
                'perguntas' => array_map(
                    fn (array $p) => $this->linhaDaPergunta($p, $respostas[$p['chave']] ?? null),
                    $perguntas,
                ),
            ];
        }, Rubrica::secoesPontuadas());
    }

    /**
     * Todas as avaliações **concluídas** de um projeto, uma coluna por
     * avaliador: a nota final de cada um e quanto ele deu em cada seção.
     *
     * É a leitura que a lista de disparidade pede. Ela diz que a maior e a
     * menor nota estão a 4,60 de distância; só a comparação por seção diz se a
     * briga foi na metodologia ou no vídeo — e o nome de quem deu cada nota,
     * que é quem o admin vai procurar depois.
     *
     * **Cada avaliação aberta vira um registro**, como no Ver notas de uma só:
     * abrir a comparação é ler as N notas, e o registro conta acessos.
     *
     * @return array<string, mixed>
     */
    public function compararProjeto(Projeto $projeto, User $admin): array
    {
        $projeto->loadMissing('area:id,nome');

        $avaliacoes = Avaliacao::where('projeto_id', $projeto->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->with('avaliador:id,name')
            ->get()
            ->sortByDesc(fn (Avaliacao $a) => $this->nota($a))
            ->values();

        $colunas = $avaliacoes->map(function (Avaliacao $a) use ($projeto, $admin) {
            $nota = $this->nota($a);

            $this->registros->notasVisualizadas(
                $projeto,
                $admin,
                $a->avaliador?->name ?? 'Avaliador #'.$a->avaliador_id,
                $nota,
            );

            return [
                'avaliacao_id' => $a->id,
                'avaliador_id' => $a->avaliador_id,
                'avaliador' => $a->avaliador?->name ?? 'Avaliador removido',
                'nota' => $nota,
                'concluida_em' => $a->concluida_em?->toIso8601String(),
                'concluida_em_label' => $a->concluida_em?->format('d/m/Y H:i'),
                // Só os pontos por seção: o detalhe pergunta a pergunta é da
                // tela de uma avaliação só, que é onde ele cabe.
                'secoes' => collect($this->secoes($a))
                    ->mapWithKeys(fn (array $s) => [$s['chave'] => $s['pontos']])
                    ->all(),
                'recomendacao_video' => $a->comentario_video,
                'recomendacao_projeto' => $a->comentario_projeto,
            ];
        })->all();

        $notas = $avaliacoes->map(fn (Avaliacao $a) => $this->nota($a));

        return [
            'projeto' => [
                'id' => $projeto->id,
                'titulo' => $projeto->titulo,
                'area' => $projeto->area?->nome,
                'categoria' => $projeto->categoria?->label(),
            ],
            // O cabeçalho das linhas é comum a todos os avaliadores: a rubrica
            // é a mesma, e é isso que torna a comparação legítima.
            'secoes' => array_map(fn (array $s) => [
                'chave' => $s['chave'],
                'titulo' => $s['titulo'],
                'maximo' => round($s['maximo'], 2),
            ], Rubrica::secoesPontuadas()),
            'avaliadores' => $colunas,
            'nota_maxima' => Rubrica::NOTA_MAXIMA,
            'media' => $notas->isEmpty() ? null : round($notas->avg(), 2),
            'amplitude' => $notas->count() < 2 ? null : round($notas->max() - $notas->min(), 2),
        ];
    }

    /**
     * A nota que vale: a gravada na conclusão. Só quando ela falta é que a
     * recalculada entra — e aí a divergência é problema de outra ordem.
     */
    public function nota(Avaliacao $avaliacao): float
    {
        return round((float) ($avaliacao->nota ?? $avaliacao->notaCalculada()), 2);
    }

    /**
     * Uma linha do detalhe: o que foi perguntado, o que o avaliador respondeu e
     * quanto aquilo rendeu.
     *
     * A resposta sai com o **rótulo** da escala ("Bom"), e não só o número: é
     * assim que ela aparece para quem avaliou, e o admin precisa ler a mesma
     * coisa que o avaliador leu.
     *
     * @param  array<string, mixed>  $pergunta
     * @return array<string, mixed>
     */
    private function linhaDaPergunta(array $pergunta, mixed $resposta): array
    {
        $peso = round((float) $pergunta['peso'], 4);

        if ($pergunta['tipo'] === Rubrica::TIPO_SIM_NAO) {
            $rotulo = $resposta === null ? null : ($resposta ? 'Sim' : 'Não');
            $pontos = $resposta ? $peso : 0.0;
        } else {
            $valor = $resposta === null || $resposta === '' ? null : (int) $resposta;
            $rotulo = $valor === null ? null : ($valor.' — '.(Rubrica::ESCALA[$valor] ?? '—'));
            $pontos = $valor === null ? 0.0 : ($valor / max(array_keys(Rubrica::ESCALA))) * $peso;
        }

        return [
            'chave' => $pergunta['chave'],
            // `rotulo` é o nome curto do quesito e `texto` é a pergunta inteira:
            // os dois vão, porque a tela usa um como título e o outro como corpo.
            'rotulo' => $pergunta['rotulo'] ?? $pergunta['chave'],
            'pergunta' => $pergunta['texto'] ?? $pergunta['rotulo'] ?? $pergunta['chave'],
            'tipo' => $pergunta['tipo'],
            // Sem resposta é diferente de resposta zero: a tela precisa dizer
            // "não respondida" em vez de fingir um "Não possui".
            'resposta' => $rotulo,
            'respondida' => $rotulo !== null,
            'peso' => $peso,
            'pontos' => round($pontos, 2),
        ];
    }
}
