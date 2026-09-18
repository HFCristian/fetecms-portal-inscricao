<?php

namespace App\Services;

use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Projeto;
use App\Support\Rubrica;
use Illuminate\Support\Collection;

/**
 * Verificar disparidade → **Identificação de padrões**.
 *
 * A primeira aba olha para o **projeto**: onde as notas se afastaram. Esta olha
 * para o **avaliador**, e responde outra pergunta — "alguém está avaliando de um
 * jeito que não é avaliar?".
 *
 * São dois abusos conhecidos e opostos, e os dois passam despercebidos num
 * ranking que só mostra médias:
 *
 * - quem **dá nota máxima em tudo** sem ler, para fechar as avaliações e sacar o
 *   certificado — o trabalho dele não separa ninguém, e ainda puxa para cima a
 *   média de quem ele tocou;
 * - quem **afunda um projeto** de propósito. Uma nota baixa isolada é legítima
 *   (ela é o motivo de existirem três avaliadores); o que denuncia é a nota
 *   sistematicamente muito abaixo da dos colegas **nos mesmos trabalhos**.
 *
 * Mais dois sinais de preenchimento sem leitura: a **mesma resposta em todas as
 * perguntas** (ou duas avaliações idênticas entre si) e a avaliação **concluída
 * minutos depois de aberta**, tempo que não dá para ler o projeto e ver o vídeo.
 *
 * Nada aqui é veredito: a tela diz "olhe para este" e mostra os números que
 * sustentam a suspeita. Quem decide desconsiderar uma nota é o admin, com
 * justificativa, na tela de notas do projeto.
 *
 * Por isso a análise **não grava nada**: é consulta, e os limiares são ajustados
 * até o admin achar o corte que faz sentido na edição dele — registrar cada
 * tentativa encheria a trilha de ruído. O que fica registrado é a ação que vem
 * depois.
 */
class PadroesAvaliacaoService
{
    /** Média a partir da qual as notas de alguém são "infladas". */
    public const MEDIA_ALTA = 9.5;

    /** Quanto a nota precisa ficar abaixo da dos colegas para chamar atenção. */
    public const DESVIO_ABAIXO = 2.0;

    /** Avaliação concluída em menos minutos do que isto é "relâmpago". */
    public const MINUTOS_RELAMPAGO = 10;

    /**
     * Ninguém entra na análise com menos avaliações do que isto: uma ou duas
     * não formam padrão, e acusar alguém por elas seria ruído.
     */
    public const MIN_AVALIACOES = 3;

    /** Quantas vezes o sinal precisa se repetir para virar padrão. */
    private const MIN_REPETICOES = 2;

    /**
     * Quantos projetos precisam estar abaixo dos colegas para o sinal disparar.
     *
     * O critério é a **repetição**, e não a média dos desvios: uma única nota
     * muito abaixo puxa a média inteira para baixo, e nota baixa isolada é o
     * caso legítimo — é justamente por causa dele que um projeto tem três
     * avaliadores. Sistemático é o que se repete, então o sinal exige que a
     * distância apareça em **pelo menos metade** dos projetos comparáveis, e em
     * no mínimo dois.
     */
    private const MIN_COMPARAVEIS = 2;

    /**
     * Os avaliadores que dispararam ao menos um sinal, do mais suspeito para o
     * menos.
     *
     * @param  array<string, mixed>  $limiares
     * @return array<string, mixed>
     */
    public function analisar(array $limiares = []): array
    {
        $mediaAlta = (float) ($limiares['media_alta'] ?? self::MEDIA_ALTA);
        $desvioAbaixo = (float) ($limiares['desvio_abaixo'] ?? self::DESVIO_ABAIXO);
        $minutos = (int) ($limiares['minutos_relampago'] ?? self::MINUTOS_RELAMPAGO);
        $minAvaliacoes = max(1, (int) ($limiares['min_avaliacoes'] ?? self::MIN_AVALIACOES));

        $avaliacoes = $this->concluidas();
        // A média de cada projeto é o termo de comparação: "abaixo dos colegas"
        // só quer dizer alguma coisa dentro do mesmo trabalho.
        $porProjeto = $avaliacoes->groupBy('projeto_id');

        $analisados = 0;
        $linhas = [];

        foreach ($avaliacoes->groupBy('avaliador_id') as $avaliadorId => $suas) {
            if ($suas->count() < $minAvaliacoes) {
                continue;
            }

            $analisados++;
            $linha = $this->perfil($avaliadorId, $suas, $porProjeto);
            $linha['padroes'] = $this->padroes($linha, $mediaAlta, $desvioAbaixo, $minutos);

            if ($linha['padroes'] !== []) {
                $linhas[] = $linha;
            }
        }

        // Quem disparou mais sinais primeiro; no empate, a maior distância dos
        // colegas — que é o sinal mais grave dos quatro.
        usort($linhas, fn (array $a, array $b) => [count($b['padroes']), -($b['desvio_medio'] ?? 0)]
            <=> [count($a['padroes']), -($a['desvio_medio'] ?? 0)]);

        return [
            'limiares' => [
                'media_alta' => round($mediaAlta, 2),
                'desvio_abaixo' => round($desvioAbaixo, 2),
                'minutos_relampago' => $minutos,
                'min_avaliacoes' => $minAvaliacoes,
            ],
            'padroes' => self::catalogo(),
            'nota_maxima' => Rubrica::NOTA_MAXIMA,
            'analisados' => $analisados,
            'total' => count($linhas),
            'avaliadores' => $linhas,
        ];
    }

    /**
     * O que cada sinal quer dizer — o mesmo texto no servidor e na tela, para a
     * legenda não descrever uma regra diferente da que roda.
     *
     * @return list<array{chave:string, titulo:string, descricao:string}>
     */
    public static function catalogo(): array
    {
        return [
            [
                'chave' => 'notas_infladas',
                'titulo' => 'Notas infladas',
                'descricao' => 'A média das notas dele passa do limiar, ou ele deu a nota máxima em todas as avaliações.',
            ],
            [
                'chave' => 'fora_da_curva',
                'titulo' => 'Notas muito abaixo dos colegas',
                'descricao' => 'Na maioria dos projetos que dividiu com outros avaliadores, a nota dele ficou bem abaixo da deles. Uma nota baixa isolada não conta: ela é o motivo de o projeto ter três pareceres.',
            ],
            [
                'chave' => 'respostas_repetidas',
                'titulo' => 'Respostas repetidas',
                'descricao' => 'A mesma resposta em todas as perguntas da rubrica, ou avaliações idênticas entre si.',
            ],
            [
                'chave' => 'relampago',
                'titulo' => 'Avaliação relâmpago',
                'descricao' => 'Enviada poucos minutos depois de aberta — tempo curto demais para ler o projeto e ver o vídeo.',
            ],
        ];
    }

    /**
     * Todas as avaliações concluídas que contam para a classificação: projeto
     * de orientador demo fica de fora, como no ranking e na lista final.
     *
     * @return Collection<int, Avaliacao>
     */
    private function concluidas(): Collection
    {
        return Avaliacao::where('status', StatusAvaliacao::Concluida->value)
            ->whereHas('projeto', fn ($q) => $q->semDemo())
            ->whereHas('avaliador', fn ($q) => $q->where('is_demo', false))
            ->with(['avaliador:id,name,email', 'avaliador.avaliadorProfile.area:id,nome', 'projeto:id,titulo'])
            ->get();
    }

    /**
     * Os números de um avaliador: média, quanto ele fica dos colegas, quantas
     * avaliações uniformes e quantas relâmpago.
     *
     * @param  Collection<int, Avaliacao>  $suas
     * @param  Collection<int, Collection<int, Avaliacao>>  $porProjeto
     * @return array<string, mixed>
     */
    private function perfil(int $avaliadorId, Collection $suas, Collection $porProjeto): array
    {
        $avaliador = $suas->first()->avaliador;
        $notas = $suas->map(fn (Avaliacao $a) => $this->nota($a));

        $desvios = [];
        $itens = [];

        foreach ($suas as $a) {
            $outras = $porProjeto->get($a->projeto_id, collect())
                ->reject(fn (Avaliacao $o) => $o->id === $a->id);

            $mediaOutros = $outras->isEmpty() ? null : round($outras->avg(fn (Avaliacao $o) => $this->nota($o)), 2);
            $desvio = $mediaOutros === null ? null : round($this->nota($a) - $mediaOutros, 2);

            if ($desvio !== null) {
                $desvios[] = $desvio;
            }

            $itens[] = [
                'avaliacao_id' => $a->id,
                'projeto_id' => $a->projeto_id,
                'projeto' => $a->projeto?->titulo,
                'nota' => $this->nota($a),
                'media_outros' => $mediaOutros,
                'desvio' => $desvio,
                'minutos' => $this->minutos($a),
                'uniforme' => $this->uniforme($a),
            ];
        }

        // A mais abaixo dos colegas primeiro: é a que o admin quer abrir.
        usort($itens, fn (array $x, array $y) => ($x['desvio'] ?? 0) <=> ($y['desvio'] ?? 0));

        $assinaturas = $suas
            ->map(fn (Avaliacao $a) => $this->assinatura($a))
            ->filter()
            ->countBy();

        return [
            'avaliador_id' => $avaliadorId,
            'avaliador' => $avaliador?->name ?? 'Avaliador removido',
            'email' => $avaliador?->email,
            'area' => $avaliador?->avaliadorProfile?->area?->nome,
            'concluidas' => $suas->count(),
            'media' => round($notas->avg(), 2),
            'nota_minima' => round($notas->min(), 2),
            'nota_maxima_dada' => round($notas->max(), 2),
            // Quantas vezes ele deu a nota cheia da rubrica.
            'notas_maximas' => $notas->filter(fn (float $n) => $n >= Rubrica::NOTA_MAXIMA)->count(),
            'comparaveis' => count($desvios),
            'desvio_medio' => $desvios === [] ? null : round(array_sum($desvios) / count($desvios), 2),
            'uniformes' => collect($itens)->where('uniforme', true)->count(),
            // Duas avaliações com exatamente as mesmas respostas: o excedente de
            // cada assinatura repetida.
            'duplicadas' => $assinaturas->filter(fn (int $n) => $n > 1)->sum(fn (int $n) => $n - 1),
            // Quantas têm duração medida — o resto foi concluída antes de o
            // portal marcar a abertura, e a tela precisa dizer isso.
            'com_duracao' => collect($itens)->whereNotNull('minutos')->count(),
            'itens' => $itens,
        ];
    }

    /**
     * Quais sinais este avaliador dispara.
     *
     * @param  array<string, mixed>  $linha
     * @return list<array<string, mixed>>
     */
    private function padroes(array $linha, float $mediaAlta, float $desvioAbaixo, int $minutos): array
    {
        $padroes = [];
        $titulos = collect(self::catalogo())->keyBy('chave');

        $todasMaximas = $linha['notas_maximas'] === $linha['concluidas'];

        if ($linha['media'] >= $mediaAlta || $todasMaximas) {
            $padroes[] = [
                'chave' => 'notas_infladas',
                'titulo' => $titulos['notas_infladas']['titulo'],
                'detalhe' => $todasMaximas
                    ? 'Nota máxima nas '.$linha['concluidas'].' avaliações.'
                    : 'Média '.$this->numero($linha['media']).' em '.$linha['concluidas'].' avaliações.',
            ];
        }

        $abaixo = collect($linha['itens'])
            ->filter(fn (array $i) => $i['desvio'] !== null && $i['desvio'] <= -$desvioAbaixo)
            ->count();

        if ($abaixo >= self::MIN_COMPARAVEIS && $abaixo >= ceil($linha['comparaveis'] / 2)) {
            $padroes[] = [
                'chave' => 'fora_da_curva',
                'titulo' => $titulos['fora_da_curva']['titulo'],
                'detalhe' => $this->numero($desvioAbaixo).' ou mais abaixo dos colegas em '
                    .$abaixo.' de '.$linha['comparaveis'].' projetos'
                    .($linha['desvio_medio'] === null
                        ? '.'
                        : ' (na média, '.$this->numero($linha['desvio_medio']).').'),
            ];
        }

        if ($linha['uniformes'] >= self::MIN_REPETICOES || $linha['duplicadas'] >= 1) {
            $detalhe = [];

            if ($linha['uniformes'] >= self::MIN_REPETICOES) {
                $detalhe[] = $linha['uniformes'].' avaliações com a mesma resposta em todas as perguntas';
            }

            if ($linha['duplicadas'] >= 1) {
                $detalhe[] = $linha['duplicadas'].' avaliação(ões) idêntica(s) a outra dele';
            }

            $padroes[] = [
                'chave' => 'respostas_repetidas',
                'titulo' => $titulos['respostas_repetidas']['titulo'],
                'detalhe' => ucfirst(implode('; ', $detalhe)).'.',
            ];
        }

        $rapidas = collect($linha['itens'])
            ->filter(fn (array $i) => $i['minutos'] !== null && $i['minutos'] < $minutos)
            ->count();

        if ($rapidas >= self::MIN_REPETICOES) {
            $padroes[] = [
                'chave' => 'relampago',
                'titulo' => $titulos['relampago']['titulo'],
                'detalhe' => $rapidas.' avaliações enviadas em menos de '.$minutos.' minutos.',
            ];
        }

        return $padroes;
    }

    /** A nota que vale: a gravada na conclusão. */
    private function nota(Avaliacao $avaliacao): float
    {
        return round((float) ($avaliacao->nota ?? $avaliacao->notaCalculada()), 2);
    }

    /**
     * Quanto tempo a avaliação levou, da primeira abertura ao envio. Nulo para
     * as que foram concluídas antes de o portal passar a marcar a abertura —
     * elas ficam de fora do sinal em vez de virar um número inventado.
     */
    private function minutos(Avaliacao $avaliacao): ?int
    {
        if ($avaliacao->iniciada_em === null || $avaliacao->concluida_em === null) {
            return null;
        }

        return (int) $avaliacao->iniciada_em->diffInMinutes($avaliacao->concluida_em);
    }

    /**
     * A mesma resposta em todas as perguntas de escala — o preenchimento que
     * não distingue nada dentro do projeto.
     */
    private function uniforme(Avaliacao $avaliacao): bool
    {
        $escala = collect(Rubrica::perguntas())
            ->where('tipo', Rubrica::TIPO_ESCALA)
            ->map(fn (array $p) => $avaliacao->respostas[$p['chave']] ?? null)
            ->filter(fn ($v) => $v !== null && $v !== '');

        return $escala->count() >= 2 && $escala->unique()->count() === 1;
    }

    /**
     * Impressão digital das respostas: duas avaliações com a mesma assinatura
     * foram preenchidas igualzinho, em projetos diferentes.
     */
    private function assinatura(Avaliacao $avaliacao): ?string
    {
        $respostas = $avaliacao->respostas ?? [];

        if ($respostas === []) {
            return null;
        }

        ksort($respostas);

        return md5((string) json_encode($respostas));
    }

    /** "1,25" — número em pt_BR para as frases do detalhe. */
    private function numero(float $valor): string
    {
        return number_format($valor, 2, ',', '');
    }
}
