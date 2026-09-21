<?php

namespace App\Services;

use App\Models\Avaliacao;
use App\Support\Rubrica;
use Illuminate\Support\Collection;

/**
 * A metade "pareceres" da aba **Ajustes e Pareceres** do orientador: em que
 * etapas da rubrica o projeto foi bem e em quais ficou devendo.
 *
 * Duas coisas **nunca** saem daqui:
 *
 * - a **nota de cada seção** da rubrica;
 * - e a **nota que o projeto recebeu** — a de cada avaliador e a média delas.
 *
 * As duas são deliberadas. O orientador precisa saber onde o trabalho foi bem e
 * onde ficou devendo, e para isso a seção vira um **nível** — ponto forte,
 * médio ou fraco. Devolver "0,74 de 1,075 em Metodologia" convidaria à
 * recontagem do que já está fechado; devolver "Metodologia: ponto médio" diz o
 * que fazer no ano que vem. A nota final é da mesma família: é ela que
 * classifica o projeto e monta a lista final, e discutir o número é assunto da
 * organização (Ranking → Verificar disparidade), não da tela de quem
 * inscreveu. Por isso nenhuma das duas vai no payload desta tela: o que não é
 * enviado não vaza.
 *
 * Como cada seção vale um peso diferente (0,15 no Título, 2,00 no Vídeo), o
 * nível sai da seção **normalizada em 0 a 10** — quanto o projeto tirou do que
 * aquela seção valia. Sem isso, o Título nunca seria um ponto forte.
 *
 * Os níveis são **agregados** entre os avaliadores: um só conjunto de pontos
 * fortes/médios/fracos por projeto. A divergência entre avaliadores é assunto
 * da organização (Ranking → Verificar disparidade), não do orientador — e o
 * avaliador é **anônimo** para ele, aqui como nas sugestões.
 *
 * A janela é a do período de ajustes, resolvida pelo
 * {@see AjustesOrientadorService}: as duas metades abrem juntas, porque são o
 * mesmo momento — a feira abrindo o resultado da avaliação para quem inscreveu.
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

    /**
     * Cada seção pontuada da rubrica com o **nível** que a média dos
     * avaliadores lhe dá — e sem a pontuação, de propósito.
     *
     * Recebe as avaliações que a tela já carregou, em vez de buscá-las de novo:
     * é a mesma lista que numera "Avaliador 1", "Avaliador 2" nas sugestões e
     * nas recomendações, e as duas metades da aba têm de falar do mesmo
     * conjunto.
     *
     * Seção que nenhuma avaliação respondeu (as anteriores à rubrica atual não
     * têm respostas guardadas) sai com `nivel` nulo: dizer "não avaliado" é
     * honesto; transformar a ausência num ponto fraco não seria.
     *
     * @param  Collection<int, Avaliacao>  $avaliacoes  concluídas e consideradas
     * @return list<array{chave:string, titulo:string, nivel:string|null, nivel_label:string}>
     */
    public function secoes(Collection $avaliacoes): array
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
}
