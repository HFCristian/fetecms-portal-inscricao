<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use App\Support\LimitesAvaliacao;
use App\Support\RegrasDistribuicao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A fila de trabalho do avaliador: manter na tela dele o mínimo de projetos
 * definido pelo admin. Concluída uma avaliação, entra outro projeto no lugar.
 *
 * A escolha segue a prioridade do edital:
 *   1. mesma ÁREA e mesma SUBÁREA do avaliador;
 *   2. mesma ÁREA;
 *   3. área CORRELATA (o grupo de áreas irmãs da área do avaliador);
 *
 * "Área do avaliador" inclui as áreas extras que o admin liberou para ele.
 *
 *   4. sorteio, quando tudo que se encaixa em 1–3 já alcançou o mínimo de
 *      avaliações — aí vale qualquer projeto submetido, com os que ainda estão
 *      abaixo do mínimo na frente.
 *
 * Dentro de cada faixa vem primeiro o projeto com menos avaliações recebidas e
 * em andamento; empatou, o menos designado. Nunca devolve projeto que o
 * avaliador já tenha (designado, em andamento ou concluído) e respeita tanto o
 * teto de avaliadores por projeto quanto o bloqueio individual do avaliador.
 *
 * O bolo de candidatos também respeita as {@see RegrasDistribuicao} do admin
 * (Avaliação Online → Algoritmo de distribuição), inclusive no sorteio: o que a
 * regra exclui não entra na fila de ninguém automaticamente.
 *
 * **Cota justa** (Sprint 95): o tamanho da fila é o mínimo por avaliador, mas
 * nunca mais do que a área comporta dividida pelos avaliadores que a atendem
 * ({@see self::cotaJusta()}). Sem isso, quem chegava primeiro saía com a fila
 * cheia e os últimos ficavam sem projeto nenhum — o trabalho acabava antes da
 * vez deles.
 *
 * **Piso da fila** (Sprint 85): uma regra restritiva pode deixar tão pouco
 * projeto elegível que o avaliador fica sem trabalho. Por isso, quando a
 * primeira passada não alcança o piso definido pelo admin
 * ({@see Edicao::pisoFilaAvaliador()}), uma segunda passada completa a fila
 * **ignorando as regras por categoria** — a "regra geral". A ordem importa: a
 * regra escolhe quem entra primeiro, e o piso só evita que alguém fique parado.
 */
class FilaAvaliadorService
{
    /**
     * Completa a fila do avaliador até o mínimo por avaliador da edição.
     * Devolve quantas designações novas foram criadas.
     *
     * Se as regras por categoria deixarem a fila abaixo do **piso** da edição,
     * uma segunda passada a completa ignorando essas regras.
     *
     * @param  list<int>  $ignorar  Projetos que não devem voltar nesta rodada.
     */
    public function repor(User $avaliador, array $ignorar = []): int
    {
        $criadas = $this->preencher($avaliador, $ignorar);

        return $criadas + $this->completarAtePiso($avaliador, $ignorar);
    }

    /**
     * A segunda passada: leva a fila até o piso **ignorando as regras por
     * categoria**. Só roda quando a passada normal deixou o avaliador abaixo do
     * piso — se a regra já deu trabalho suficiente, ela continua valendo
     * sozinha.
     *
     * @param  list<int>  $ignorar
     */
    private function completarAtePiso(User $avaliador, array $ignorar = []): int
    {
        $piso = Edicao::pisoFilaAvaliador();

        // Sem piso, ou com as regras já liberando tudo, não há segunda passada.
        if ($piso === null || ! Edicao::regrasDistribuicao()->restringe()) {
            return 0;
        }

        return $this->preencher($avaliador, $ignorar, alvo: $piso, ignorarRegras: true);
    }

    /**
     * O motor da reposição, usado pelas duas passadas.
     *
     * @param  list<int>  $ignorar
     * @param  ?int  $alvo  tamanho de fila desejado; nulo usa o mínimo por avaliador
     */
    private function preencher(User $avaliador, array $ignorar = [], ?int $alvo = null, bool $ignorarRegras = false): int
    {
        $perfil = $avaliador->avaliadorProfile;

        // Sem área (nem própria nem extra) não há como casar projeto; conta demo
        // ou inativa fica de fora da reposição, como já acontece na distribuição.
        if (($perfil?->areasAtendidas() ?? []) === [] || $avaliador->is_demo || ! $avaliador->is_active) {
            return 0;
        }

        $status = Avaliacao::where('avaliador_id', $avaliador->id)->pluck('status');
        $pendentes = $status->filter(fn ($s) => $s !== StatusAvaliacao::Concluida)->count();
        $assumidas = $status->filter(fn ($s) => $s !== StatusAvaliacao::Designada)->count();

        if ($perfil->atingiuLimite($assumidas)) {
            return 0; // bloqueado pelo admin: não recebe projeto novo
        }

        $limites = Edicao::limites();

        // A fila tem o tamanho do mínimo por avaliador (ou do alvo pedido pela
        // segunda passada), **limitado pela cota justa** — é o que impede um
        // avaliador de sair com 6 projetos enquanto o colega da mesma área fica
        // sem nenhum. Quando a edição define um teto total, a fila também para
        // de crescer ao alcançá-lo.
        $vagas = min($alvo ?? $limites->minPorAvaliador(), $this->cotaJusta($perfil, $limites)) - $pendentes;

        if ($limites->maxPorAvaliador() !== null) {
            $vagas = min($vagas, $limites->maxPorAvaliador() - $status->count());
        }
        $criadas = 0;
        $escolhidos = $ignorar;

        while ($vagas > 0) {
            $projetoId = $this->proximo($avaliador, $escolhidos, $ignorarRegras);
            if ($projetoId === null) {
                break; // acabaram os projetos elegíveis
            }

            Avaliacao::create([
                'projeto_id' => $projetoId,
                'avaliador_id' => $avaliador->id,
                'status' => StatusAvaliacao::Designada,
            ]);

            $escolhidos[] = $projetoId;
            $criadas++;
            $vagas--;
        }

        return $criadas;
    }

    /**
     * Quantos projetos este avaliador pode ter na fila **sem passar na frente
     * dos colegas**: o trabalho que a área dele comporta dividido pelo número
     * de avaliadores que atendem essa mesma área.
     *
     * O bolo é a soma, projeto a projeto, do **máximo de avaliadores por
     * projeto** da categoria — quantas designações a área aceita no total. Com
     * trabalho de sobra a divisão estoura o tamanho da fila e a cota some (o
     * teto volta a ser o mínimo por avaliador, como sempre foi); com trabalho
     * escasso ela reparte o que existe, em vez de deixar quem chegou primeiro
     * sair com a fila cheia e o resto sem nada.
     *
     * A divisão é para BAIXO, senão a soma das cotas passaria do que a área
     * comporta; e a cota nunca é menor que 1, porque avaliador parado é pior
     * do que projeto com um avaliador a mais. O bolo não desconta o que já foi
     * designado de propósito: fosse dinâmico, uma redistribuição encolheria a
     * fila de todo mundo só porque a cobertura já estava feita.
     */
    private function cotaJusta(AvaliadorProfile $perfil, LimitesAvaliacao $limites): int
    {
        $areas = $perfil->areasAtendidas();

        if ($areas === []) {
            return 0;
        }

        $bolo = Projeto::semDemo()
            ->where('status', ProjetoStatus::Submetido->value)
            ->whereIn('area_id', $areas)
            ->get(['id', 'categoria'])
            ->sum(fn (Projeto $p) => $limites->maxPorProjeto($p->categoria));

        // Quem divide esse bolo: todo avaliador ativo e não-demo que atende
        // alguma dessas áreas, pela própria classificação ou por liberação do
        // admin. O próprio avaliador está incluído.
        $pares = AvaliadorProfile::query()
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->where('is_demo', false))
            ->where(fn ($q) => $q->whereIn('area_id', $areas)
                ->orWhereHas('areasExtras', fn ($e) => $e->whereIn('area_id', $areas)))
            ->count();

        return max(1, intdiv((int) $bolo, max(1, $pares)));
    }

    /**
     * Sorteia de novo a fila do avaliador: devolve ao bolo os projetos que ele
     * ainda não abriu e puxa outros no lugar, pelas mesmas prioridades.
     *
     * Ficam de fora do sorteio o que já está em avaliação (iniciado não volta
     * atrás), o que já foi concluído e o que o ADMIN designou à mão. Se não
     * houver alternativa suficiente, os projetos devolvidos podem voltar — a
     * fila nunca encolhe por causa de um sorteio.
     *
     * O piso vale aqui como na reposição: se as regras não encherem a fila até
     * ele, a segunda passada completa ignorando-as.
     *
     * @return array{trocados:int, recebidos:int}
     */
    public function roletar(User $avaliador): array
    {
        $devolvidos = Avaliacao::where('avaliador_id', $avaliador->id)
            ->where('status', StatusAvaliacao::Designada->value)
            ->where('designacao_manual', false)
            ->get(['id', 'projeto_id']);

        if ($devolvidos->isEmpty()) {
            return ['trocados' => 0, 'recebidos' => 0];
        }

        $projetosDevolvidos = $devolvidos->pluck('projeto_id')->all();

        $recebidos = DB::transaction(function () use ($avaliador, $devolvidos, $projetosDevolvidos) {
            Avaliacao::whereIn('id', $devolvidos->pluck('id'))->delete();

            // Primeiro tenta só com projetos diferentes; se faltar gente na área,
            // uma segunda passada aceita de volta os que acabaram de sair.
            $novos = $this->repor($avaliador, $projetosDevolvidos);

            return $novos + $this->repor($avaliador);
        });

        return ['trocados' => count($projetosDevolvidos), 'recebidos' => $recebidos];
    }

    /**
     * O próximo projeto para este avaliador, pela ordem de prioridade — ou null
     * se não sobrou nenhum elegível.
     *
     * @param  list<int>  $ignorar  Projetos já escolhidos nesta mesma rodada.
     * @param  bool  $ignorarRegras  Passa por cima das regras por categoria — é o
     *                               que a segunda passada do piso usa. Os tetos
     *                               por projeto e por avaliador continuam valendo.
     */
    public function proximo(User $avaliador, array $ignorar = [], bool $ignorarRegras = false): ?int
    {
        $perfil = $avaliador->avaliadorProfile;
        // A própria área do avaliador mais as que o admin liberou para ele.
        $areas = $perfil?->areasAtendidas() ?? [];

        if ($areas === []) {
            return null;
        }

        $limites = Edicao::limites();

        // Projetos que o avaliador já viu não voltam para a fila dele.
        $jaTem = Avaliacao::where('avaliador_id', $avaliador->id)->pluck('projeto_id')->all();
        $regras = Edicao::regrasDistribuicao();

        // `semDemo` pelo mesmo motivo da distribuição em massa: projeto de
        // orientador demo só chega a um avaliador por designação manual.
        $candidatos = Projeto::semDemo()
            ->where('status', ProjetoStatus::Submetido->value)
            ->whereNotIn('id', [...$jaTem, ...$ignorar])
            ->select(['id', 'area_id', 'subarea_id', 'categoria'])
            ->withCount([
                'avaliacoes as concluidas_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
                'avaliacoes as em_andamento_count' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
                'avaliacoes as total_count',
            ])
            ->get()
            ->filter(fn (Projeto $p) => $p->total_count < $limites->maxPorProjeto($p->categoria)
                && ($ignorarRegras || $regras->aceita($p->categoria, $p->concluidas_count)));

        if ($candidatos->isEmpty()) {
            return null;
        }

        $correlatas = $this->areasCorrelatas($areas);
        $pares = $perfil->paresAtendidos();
        // "Precisa" é ainda ter designação a receber — o alvo do Algoritmo de
        // distribuição, que pode ser maior que a cobertura mínima. Assim a fila
        // e a distribuição em massa perseguem o mesmo número.
        $precisa = fn (Projeto $p) => $p->total_count < $limites->designacoesPorProjeto($p->categoria);

        $faixas = [
            // 1. área e subárea que o avaliador atende
            fn (Projeto $p) => $precisa($p) && $p->subarea_id !== null
                && in_array([$p->area_id, $p->subarea_id], $pares, true),
            // 2. área que o avaliador atende
            fn (Projeto $p) => $precisa($p) && in_array($p->area_id, $areas, true),
            // 3. área correlata
            fn (Projeto $p) => $precisa($p) && in_array($p->area_id, $correlatas, true),
        ];

        foreach ($faixas as $faixa) {
            $faixaCandidatos = $candidatos->filter($faixa);

            if ($faixaCandidatos->isNotEmpty()) {
                return $this->menosCarregado($faixaCandidatos);
            }
        }

        // 4. Sorteio: os que ainda estão abaixo do mínimo entram primeiro.
        $abaixoDoMinimo = $candidatos->filter($precisa);
        $sorteio = $abaixoDoMinimo->isNotEmpty() ? $abaixoDoMinimo : $candidatos;

        return (int) $sorteio->random()->id;
    }

    /**
     * O menos carregado da faixa: menos avaliações recebidas e em andamento;
     * empate resolvido por menos designações no total e, por fim, pelo id.
     */
    private function menosCarregado(Collection $candidatos): int
    {
        return (int) $candidatos
            ->sortBy(fn (Projeto $p) => [
                $p->concluidas_count + $p->em_andamento_count,
                $p->total_count,
                $p->id,
            ])
            ->first()
            ->id;
    }

    /**
     * Áreas irmãs das áreas do avaliador (mesmo grupo de correlação), sem
     * repetir as que ele já atende. Área sem grupo não tem irmã.
     *
     * @param  list<int>  $areas
     * @return list<int>
     */
    private function areasCorrelatas(array $areas): array
    {
        $grupos = Area::whereIn('id', $areas)
            ->whereNotNull('grupo_correlato')
            ->pluck('grupo_correlato')
            ->unique()
            ->all();

        if ($grupos === []) {
            return [];
        }

        return Area::whereIn('grupo_correlato', $grupos)
            ->whereNotIn('id', $areas)
            ->pluck('id')
            ->all();
    }
}
