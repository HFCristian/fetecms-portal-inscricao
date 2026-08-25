<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\StatusAvaliacao;
use App\Models\Area;
use App\Models\Avaliacao;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
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
 *   4. sorteio, quando tudo que se encaixa em 1–3 já alcançou o mínimo de
 *      avaliações — aí vale qualquer projeto submetido, com os que ainda estão
 *      abaixo do mínimo na frente.
 *
 * Dentro de cada faixa vem primeiro o projeto com menos avaliações recebidas e
 * em andamento; empatou, o menos designado. Nunca devolve projeto que o
 * avaliador já tenha (designado, em andamento ou concluído) e respeita tanto o
 * teto de avaliadores por projeto quanto o bloqueio individual do avaliador.
 */
class FilaAvaliadorService
{
    /**
     * Completa a fila do avaliador até o mínimo por avaliador da edição.
     * Devolve quantas designações novas foram criadas.
     *
     * @param  list<int>  $ignorar  Projetos que não devem voltar nesta rodada.
     */
    public function repor(User $avaliador, array $ignorar = []): int
    {
        $perfil = $avaliador->avaliadorProfile;

        // Sem área não há como casar projeto; conta demo ou inativa fica de fora
        // da reposição automática, como já acontece na distribuição.
        if (! $perfil?->area_id || $avaliador->is_demo || ! $avaliador->is_active) {
            return 0;
        }

        $status = Avaliacao::where('avaliador_id', $avaliador->id)->pluck('status');
        $pendentes = $status->filter(fn ($s) => $s !== StatusAvaliacao::Concluida)->count();
        $assumidas = $status->filter(fn ($s) => $s !== StatusAvaliacao::Designada)->count();

        if ($perfil->atingiuLimite($assumidas)) {
            return 0; // bloqueado pelo admin: não recebe projeto novo
        }

        $vagas = Edicao::minPorAvaliador() - $pendentes;
        $criadas = 0;
        $escolhidos = $ignorar;

        while ($vagas > 0) {
            $projetoId = $this->proximo($avaliador, $escolhidos);
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
     * Sorteia de novo a fila do avaliador: devolve ao bolo os projetos que ele
     * ainda não abriu e puxa outros no lugar, pelas mesmas prioridades.
     *
     * Ficam de fora do sorteio o que já está em avaliação (iniciado não volta
     * atrás), o que já foi concluído e o que o ADMIN designou à mão. Se não
     * houver alternativa suficiente, os projetos devolvidos podem voltar — a
     * fila nunca encolhe por causa de um sorteio.
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
     */
    public function proximo(User $avaliador, array $ignorar = []): ?int
    {
        $perfil = $avaliador->avaliadorProfile;
        if (! $perfil?->area_id) {
            return null;
        }

        $minPorProjeto = Edicao::minPorProjeto();
        $teto = max(Avaliacao::TETO_POR_PROJETO, $minPorProjeto);

        // Projetos que o avaliador já viu não voltam para a fila dele.
        $jaTem = Avaliacao::where('avaliador_id', $avaliador->id)->pluck('projeto_id')->all();

        $candidatos = Projeto::query()
            ->where('status', ProjetoStatus::Submetido->value)
            ->whereNotIn('id', [...$jaTem, ...$ignorar])
            ->withCount([
                'avaliacoes as concluidas_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
                'avaliacoes as em_andamento_count' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
                'avaliacoes as total_count',
            ])
            ->get(['id', 'area_id', 'subarea_id'])
            ->filter(fn (Projeto $p) => $p->total_count < $teto);

        if ($candidatos->isEmpty()) {
            return null;
        }

        $correlatas = $this->areasCorrelatas($perfil->area_id);
        $precisa = fn (Projeto $p) => $p->total_count < $minPorProjeto;

        $faixas = [
            // 1. área e subárea do avaliador
            fn (Projeto $p) => $precisa($p) && $p->area_id === $perfil->area_id
                && $perfil->subarea_id !== null && $p->subarea_id === $perfil->subarea_id,
            // 2. área do avaliador
            fn (Projeto $p) => $precisa($p) && $p->area_id === $perfil->area_id,
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
     * Áreas irmãs da área do avaliador (mesmo grupo de correlação). Área sem
     * grupo não tem irmã.
     *
     * @return list<int>
     */
    private function areasCorrelatas(int $areaId): array
    {
        $grupo = Area::whereKey($areaId)->value('grupo_correlato');

        if ($grupo === null) {
            return [];
        }

        return Area::where('grupo_correlato', $grupo)
            ->whereKeyNot($areaId)
            ->pluck('id')
            ->all();
    }
}
