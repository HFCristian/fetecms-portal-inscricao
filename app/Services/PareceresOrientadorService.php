<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Rubrica;
use Illuminate\Support\Collection;

/**
 * Aba "Pareceres" do orientador: o que a avaliação online disse sobre cada
 * projeto que ele submeteu.
 *
 * Duas coisas aparecem, e uma **não** aparece:
 *
 * - a **nota média** do projeto, que é o número que o classifica;
 * - as **recomendações escritas** dos avaliadores, uma a uma;
 * - e **nunca** a nota de cada seção da rubrica.
 *
 * A última é deliberada. O orientador precisa saber onde o trabalho foi bem e
 * onde ficou devendo, e para isso a seção vira um **nível** — ponto forte,
 * médio ou fraco. Devolver "0,74 de 1,075 em Metodologia" convidaria à
 * recontagem do que já está fechado; devolver "Metodologia: ponto médio" diz o
 * que fazer no ano que vem. Por isso o payload desta tela não carrega a
 * pontuação das seções: o que não é enviado não vaza.
 *
 * Como cada seção vale um peso diferente (0,15 no Título, 2,00 no Vídeo), o
 * nível sai da seção **normalizada em 0 a 10** — quanto o projeto tirou do que
 * aquela seção valia. Sem isso, o Título nunca seria um ponto forte.
 *
 * As médias são **agregadas** entre os avaliadores: um só conjunto de pontos
 * fortes/médios/fracos por projeto. A divergência entre avaliadores é assunto
 * da organização (Ranking → Verificar disparidade), não do orientador — e o
 * avaliador é **anônimo** para ele, aqui como na aba Ajustes.
 *
 * A janela é a mesma dos ajustes (`edicoes.ajustes_de`/`ajustes_ate`): é o
 * momento em que a feira abre o resultado da avaliação para quem inscreveu.
 */
class PareceresOrientadorService
{
    /** Daqui para cima, ponto forte. */
    public const CORTE_FORTE = 8.0;

    /** Daqui para cima (e abaixo do corte forte), ponto médio; abaixo, fraco. */
    public const CORTE_MEDIO = 4.0;

    public const NIVEL_FORTE = 'forte';

    public const NIVEL_MEDIO = 'medio';

    public const NIVEL_FRACO = 'fraco';

    public function __construct(private readonly AjustesOrientadorService $ajustes) {}

    /**
     * Estado da janela para esta pessoa — a mesma do período de ajustes.
     *
     * @return array<string, mixed>
     */
    public function janela(User $user, bool $teste = false): array
    {
        return $this->ajustes->janela($user, $teste);
    }

    /**
     * Os projetos submetidos do orientador, com a média e quantos pareceres
     * cada um recebeu.
     *
     * @return list<array<string, mixed>>
     */
    public function projetos(User $orientador): array
    {
        return Projeto::query()
            ->where('user_id', $orientador->id)
            ->whereIn('status', [
                ProjetoStatus::Submetido->value,
                ProjetoStatus::Aprovado->value,
                ProjetoStatus::Rejeitado->value,
            ])
            ->with(['area:id,nome', 'subarea:id,nome'])
            ->orderBy('titulo')
            ->get()
            ->map(function (Projeto $projeto) {
                $avaliacoes = $this->concluidas($projeto);

                return [
                    'id' => $projeto->id,
                    'titulo' => $projeto->titulo,
                    'area' => $projeto->area?->nome,
                    'subarea' => $projeto->subarea?->nome,
                    'avaliacoes' => $avaliacoes->count(),
                    'media' => $this->media($avaliacoes),
                    'nota_maxima' => Avaliacao::notaMaxima(),
                    'recomendacoes' => count($this->recomendacoes($avaliacoes)),
                ];
            })
            ->all();
    }

    /**
     * O parecer de um projeto: média, as seções classificadas em níveis e as
     * recomendações escritas, sem identificar quem escreveu.
     *
     * @return array<string, mixed>
     */
    public function detalhe(Projeto $projeto): array
    {
        $projeto->loadMissing(['area:id,nome', 'subarea:id,nome']);
        $avaliacoes = $this->concluidas($projeto);

        return [
            'id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'area' => $projeto->area?->nome,
            'subarea' => $projeto->subarea?->nome,
            'avaliacoes' => $avaliacoes->count(),
            'media' => $this->media($avaliacoes),
            'nota_maxima' => Avaliacao::notaMaxima(),
            'secoes' => $this->secoes($avaliacoes),
            'recomendacoes' => $this->recomendacoes($avaliacoes),
        ];
    }

    /**
     * Cada seção pontuada da rubrica com o **nível** que a média dos
     * avaliadores lhe dá — e sem a pontuação, de propósito.
     *
     * Seção que nenhuma avaliação respondeu (as anteriores à rubrica atual não
     * têm respostas guardadas) sai com `nivel` nulo: dizer "não avaliado" é
     * honesto; transformar a ausência num ponto fraco não seria.
     *
     * @param  Collection<int, Avaliacao>  $avaliacoes
     * @return list<array{chave:string, titulo:string, nivel:string|null, nivel_label:string}>
     */
    private function secoes(Collection $avaliacoes): array
    {
        $respondidas = $avaliacoes->filter(fn (Avaliacao $a) => ! empty($a->respostas));

        return array_map(function (array $secao) use ($respondidas) {
            $nivel = null;

            if ($respondidas->isNotEmpty() && $secao['maximo'] > 0) {
                $pontos = $respondidas->avg(
                    fn (Avaliacao $a) => Rubrica::pontosDaSecao($secao['chave'], $a->respostas ?? []),
                );

                // Normaliza para 0 a 10: cada seção vale um peso diferente, e o
                // nível tem de significar a mesma coisa em todas.
                $nivel = $this->nivel(($pontos / $secao['maximo']) * 10);
            }

            return [
                'chave' => $secao['chave'],
                'titulo' => $secao['titulo'],
                'nivel' => $nivel,
                'nivel_label' => $this->rotulo($nivel),
            ];
        }, Rubrica::secoesPontuadas());
    }

    private function nivel(float $normalizada): string
    {
        return match (true) {
            $normalizada >= self::CORTE_FORTE => self::NIVEL_FORTE,
            $normalizada >= self::CORTE_MEDIO => self::NIVEL_MEDIO,
            default => self::NIVEL_FRACO,
        };
    }

    private function rotulo(?string $nivel): string
    {
        return match ($nivel) {
            self::NIVEL_FORTE => 'Ponto forte',
            self::NIVEL_MEDIO => 'Ponto médio',
            self::NIVEL_FRACO => 'Ponto fraco',
            default => 'Não avaliado',
        };
    }

    /**
     * As recomendações escritas (vídeo e projeto), na ordem das avaliações — é
     * ela que dá o "Avaliador 1", "Avaliador 2". O nome de quem escreveu nunca
     * sai daqui.
     *
     * @param  Collection<int, Avaliacao>  $avaliacoes
     * @return list<array<string, mixed>>
     */
    private function recomendacoes(Collection $avaliacoes): array
    {
        $itens = [];

        foreach ($avaliacoes->values() as $i => $avaliacao) {
            foreach ([
                'video' => ['Sobre o vídeo', $avaliacao->comentario_video],
                'projeto' => ['Sobre o projeto', $avaliacao->comentario_projeto],
            ] as $chave => [$titulo, $texto]) {
                if (blank($texto)) {
                    continue;
                }

                $itens[] = [
                    'avaliador' => 'Avaliador '.($i + 1),
                    'tipo' => $chave,
                    'titulo' => $titulo,
                    'texto' => $texto,
                ];
            }
        }

        return $itens;
    }

    /** Média das notas finais, com duas casas (null sem avaliação concluída). */
    private function media(Collection $avaliacoes): ?float
    {
        $notas = $avaliacoes->pluck('nota')->filter(fn ($n) => $n !== null);

        return $notas->isEmpty() ? null : round((float) $notas->avg(), 2);
    }

    /**
     * Avaliações concluídas do projeto, sempre na mesma ordem — a mesma da aba
     * Ajustes, para "Avaliador 2" ser a mesma pessoa nas duas telas.
     *
     * @return Collection<int, Avaliacao>
     */
    private function concluidas(Projeto $projeto): Collection
    {
        return Avaliacao::where('projeto_id', $projeto->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->orderBy('concluida_em')
            ->orderBy('id')
            ->get();
    }
}
