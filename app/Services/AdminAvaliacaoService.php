<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\AvaliadorProfile;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\Subarea;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Telas de "Avaliação online" do admin (E7). O algoritmo de distribuição ainda
 * não existe, então os números vêm da tabela `avaliacoes` (zerados por enquanto).
 */
class AdminAvaliacaoService
{
    /**
     * Avaliadores agrupados por área, com o progresso de cada um:
     * em_avaliacao (em andamento agora, 0 ou 1), avaliou (concluídas) e
     * faltam (3 − avaliou, mínimo 0).
     *
     * @return array<int, array{area_id:int, area:string, avaliadores:array}>
     */
    public function avaliadoresPorArea(): array
    {
        $avaliadores = User::query()
            ->where('role', Role::Avaliador->value)
            ->with('avaliadorProfile.area:id,nome')
            ->withCount([
                'avaliacoes as em_avaliacao_count' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
                'avaliacoes as avaliou_count' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
            ])
            ->orderBy('name')
            ->get();

        $grupos = [];
        foreach ($avaliadores as $u) {
            $area = $u->avaliadorProfile?->area;
            $chave = $area?->id ?? 0;
            $grupos[$chave] ??= ['area_id' => (int) ($area?->id ?? 0), 'area' => $area?->nome ?? 'Sem área', 'avaliadores' => []];

            $avaliou = (int) $u->avaliou_count;
            $grupos[$chave]['avaliadores'][] = [
                'id' => $u->id,
                'nome' => $u->name,
                'em_avaliacao' => (int) $u->em_avaliacao_count,
                'avaliou' => $avaliou,
                'faltam' => max(0, StatusAvaliacao::MAX_POR_AVALIADOR - $avaliou),
                'limite' => $u->avaliadorProfile?->limite_avaliacoes,
                'is_demo' => (bool) $u->is_demo,
            ];
        }

        return $this->ordenarPorArea($grupos);
    }

    /**
     * Projetos submetidos agrupados por área, com quantas avaliações (concluídas)
     * cada um já recebeu.
     *
     * @return array<int, array{area_id:int, area:string, projetos:array}>
     */
    public function projetosSubmetidosPorArea(): array
    {
        $projetos = Projeto::query()
            ->where('status', ProjetoStatus::Submetido->value)
            ->with('area:id,nome')
            ->withCount([
                'avaliacoes as realizadas' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
                'avaliacoes as em_avaliacao' => fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento->value),
            ])
            ->orderBy('titulo')
            ->get();

        $grupos = [];
        foreach ($projetos as $p) {
            $area = $p->area;
            $chave = $area?->id ?? 0;
            $grupos[$chave] ??= ['area_id' => (int) ($area?->id ?? 0), 'area' => $area?->nome ?? 'Sem área', 'projetos' => []];

            $realizadas = (int) $p->realizadas;
            $grupos[$chave]['projetos'][] = [
                'id' => $p->id,
                'titulo' => $p->titulo,
                'realizadas' => $realizadas,
                'em_avaliacao' => (int) $p->em_avaliacao,
                // Cada projeto precisa de ao menos 3 avaliações concluídas.
                'faltantes' => max(0, StatusAvaliacao::MIN_POR_PROJETO - $realizadas),
            ];
        }

        return $this->ordenarPorArea($grupos);
    }

    /**
     * Projetos que receberam sugestão de reclassificação: pelo menos um avaliador
     * marcou a área e/ou a subárea como incorreta ao concluir. Cada projeto vem
     * com as sugestões individuais e o consenso (opção mais votada).
     *
     * Filtros (todos opcionais): `area_id` (área ATUAL do projeto), `q` (trecho do
     * título) e `de`/`ate` (período da data de avaliação, inclusive).
     *
     * @param  array{area_id?:int|null, q?:string|null, de?:string|null, ate?:string|null}  $filtros
     * @return list<array<string, mixed>>
     */
    public function reclassificacoesSugeridas(array $filtros = []): array
    {
        $avaliacoes = Avaliacao::query()
            ->where('status', StatusAvaliacao::Concluida->value)
            ->where(fn ($q) => $q->where('area_correta', false)->orWhere('subarea_correta', false))
            ->with([
                'avaliador:id,name',
                'areaSugerida:id,nome',
                'subareaSugerida:id,nome',
                'projeto:id,titulo,area_id,subarea_id',
                'projeto.area:id,nome',
                'projeto.subarea:id,nome',
            ])
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->whereHas(
                'projeto', fn ($p) => $p->where('area_id', $areaId)
            ))
            ->when($filtros['q'] ?? null, fn ($q, $termo) => $q->whereHas(
                'projeto', fn ($p) => $p->where('titulo', 'like', '%'.$termo.'%')
            ))
            ->when($filtros['de'] ?? null, fn ($q, $de) => $q->whereDate('concluida_em', '>=', $de))
            ->when($filtros['ate'] ?? null, fn ($q, $ate) => $q->whereDate('concluida_em', '<=', $ate))
            ->orderByDesc('concluida_em')
            ->get()
            // Uma avaliação sem projeto (exclusão em cascata em andamento) não tem o que exibir.
            ->filter(fn (Avaliacao $a) => $a->projeto !== null);

        $projetos = [];
        foreach ($avaliacoes as $a) {
            $projeto = $a->projeto;

            // Sugestão que aponta para a classificação ATUAL já foi aplicada pelo
            // admin — deixa de ser uma troca pendente e some da lista.
            $areaId = $a->area_correta === false && $a->area_sugerida_id
                && $a->area_sugerida_id !== $projeto->area_id ? $a->area_sugerida_id : null;
            $subareaId = $a->subarea_correta === false && $a->subarea_sugerida_id
                && $a->subarea_sugerida_id !== $projeto->subarea_id ? $a->subarea_sugerida_id : null;

            if ($areaId === null && $subareaId === null) {
                continue;
            }

            $id = $projeto->id;
            $projetos[$id] ??= [
                'projeto_id' => $id,
                'titulo' => $projeto->titulo,
                'area_id' => $projeto->area_id,
                'area' => $projeto->area?->nome,
                'subarea_id' => $projeto->subarea_id,
                'subarea' => $projeto->subarea?->nome,
                'sugestoes' => [],
            ];

            $projetos[$id]['sugestoes'][] = [
                'avaliacao_id' => $a->id,
                'avaliador' => $a->avaliador?->name,
                'avaliada_em' => $a->concluida_em?->format('d/m/Y H:i'),
                'avaliada_em_iso' => $a->concluida_em?->toIso8601String(),
                'area_sugerida_id' => $areaId,
                'area_sugerida' => $areaId ? $a->areaSugerida?->nome : null,
                'subarea_sugerida_id' => $subareaId,
                'subarea_sugerida' => $subareaId ? $a->subareaSugerida?->nome : null,
            ];
        }

        $lista = array_map(function (array $p) {
            $p['total_sugestoes'] = count($p['sugestoes']);
            // Opções distintas com a contagem de votos — o admin escolhe qual aceitar.
            $p['opcoes_area'] = $this->opcoes($p['sugestoes'], 'area_sugerida');
            $p['opcoes_subarea'] = $this->opcoes($p['sugestoes'], 'subarea_sugerida');
            $p['area_mais_sugerida'] = $p['opcoes_area'][0] ?? null;
            $p['subarea_mais_sugerida'] = $p['opcoes_subarea'][0] ?? null;

            return $p;
        }, array_values($projetos));

        // Mais sugestões primeiro: é onde o admin deve olhar antes.
        usort($lista, fn ($a, $b) => [$b['total_sugestoes'], $a['titulo']] <=> [$a['total_sugestoes'], $b['titulo']]);

        return $lista;
    }

    /**
     * Opções distintas sugeridas para um campo, com os votos de cada uma e a mais
     * votada primeiro (empate desfeito pelo nome, para a ordem ser estável).
     *
     * @param  list<array<string, mixed>>  $sugestoes
     * @return list<array{id:int, nome:string, votos:int}>
     */
    private function opcoes(array $sugestoes, string $campo): array
    {
        $contagem = [];

        foreach ($sugestoes as $s) {
            $id = $s[$campo.'_id'] ?? null;

            if ($id === null) {
                continue;
            }

            $contagem[$id] ??= ['id' => (int) $id, 'nome' => (string) $s[$campo], 'votos' => 0];
            $contagem[$id]['votos']++;
        }

        $lista = array_values($contagem);
        usort($lista, fn ($a, $b) => [$b['votos'], $a['nome']] <=> [$a['votos'], $b['nome']]);

        return $lista;
    }

    /**
     * Aplica sugestões de reclassificação em lote: troca a área e/ou a subárea dos
     * projetos indicados. Só aceita valores que algum avaliador realmente sugeriu
     * para aquele projeto — este endpoint é "aceitar sugestão", não edição livre.
     *
     * Tudo numa transação: ou o lote inteiro vale, ou nada muda.
     *
     * @param  list<array{projeto_id:int, area_id?:int|null, subarea_id?:int|null}>  $itens
     * @return list<array<string, mixed>> o que mudou em cada projeto
     */
    public function aplicarReclassificacoes(array $itens): array
    {
        return DB::transaction(function () use ($itens) {
            $aplicados = [];

            foreach ($itens as $item) {
                $projeto = Projeto::with(['area:id,nome', 'subarea:id,nome'])->find($item['projeto_id']);

                if (! $projeto) {
                    throw ValidationException::withMessages([
                        'itens' => 'Um dos projetos selecionados não existe mais.',
                    ]);
                }

                $aplicados[] = $this->aplicarNoProjeto(
                    $projeto,
                    $item['area_id'] ?? null,
                    $item['subarea_id'] ?? null,
                );
            }

            return $aplicados;
        });
    }

    /**
     * Troca a classificação de um projeto validando cada sugestão contra o que
     * foi realmente sugerido nas avaliações concluídas.
     *
     * @return array<string, mixed>
     */
    private function aplicarNoProjeto(Projeto $projeto, ?int $areaId, ?int $subareaId): array
    {
        if ($areaId === null && $subareaId === null) {
            throw ValidationException::withMessages([
                'itens' => "Nenhuma sugestão foi escolhida para \"{$projeto->titulo}\".",
            ]);
        }

        $antes = ['area' => $projeto->area?->nome, 'subarea' => $projeto->subarea?->nome];

        if ($areaId !== null) {
            $this->garantirSugerido($projeto, 'area_sugerida_id', $areaId, 'área');
        }

        if ($subareaId !== null) {
            $this->garantirSugerido($projeto, 'subarea_sugerida_id', $subareaId, 'subárea');
        }

        $novaArea = $areaId ?? $projeto->area_id;
        $novaSubarea = $subareaId ?? $projeto->subarea_id;

        // Subárea pertence a uma área só: trocar a área sem trocar a subárea
        // deixaria o projeto numa combinação inexistente no catálogo.
        if ($novaSubarea !== null && ! Subarea::where('id', $novaSubarea)->where('area_id', $novaArea)->exists()) {
            $novaSubarea = null;
        }

        $projeto->update(['area_id' => $novaArea, 'subarea_id' => $novaSubarea]);
        $projeto->load(['area:id,nome', 'subarea:id,nome']);

        return [
            'projeto_id' => $projeto->id,
            'titulo' => $projeto->titulo,
            'area_anterior' => $antes['area'],
            'area' => $projeto->area?->nome,
            'subarea_anterior' => $antes['subarea'],
            'subarea' => $projeto->subarea?->nome,
            // Avisa quando a subárea caiu junto por não pertencer à nova área.
            'subarea_limpa' => $antes['subarea'] !== null && $projeto->subarea_id === null,
        ];
    }

    /** Barra qualquer valor que não tenha sido sugerido por um avaliador do projeto. */
    private function garantirSugerido(Projeto $projeto, string $coluna, int $valor, string $rotulo): void
    {
        $sugerido = Avaliacao::where('projeto_id', $projeto->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->where($coluna, $valor)
            ->exists();

        if (! $sugerido) {
            throw ValidationException::withMessages([
                'itens' => "A {$rotulo} escolhida para \"{$projeto->titulo}\" não consta nas sugestões dos avaliadores.",
            ]);
        }
    }

    /**
     * Ranking dos projetos que já receberam ao menos uma avaliação concluída,
     * pela MÉDIA das notas finais (3 a 15). Desempate: mais avaliações primeiro
     * (média mais confiável) e, por fim, título.
     *
     * `completo` marca quem já atingiu o mínimo de avaliações — abaixo disso a
     * média ainda é parcial e não deve valer como classificação final.
     *
     * @param  array{area_id?:int|null}  $filtros
     * @return list<array<string, mixed>>
     */
    public function rankingProjetos(array $filtros = []): array
    {
        $projetos = Projeto::query()
            ->whereHas('avaliacoes', fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value))
            ->when($filtros['area_id'] ?? null, fn ($q, $areaId) => $q->where('area_id', $areaId))
            ->with([
                'area:id,nome',
                'avaliacoes' => fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value),
            ])
            ->get();

        $lista = $projetos->map(function (Projeto $p) {
            $concluidas = $p->avaliacoes;
            $total = $concluidas->count();

            return [
                'projeto_id' => $p->id,
                'titulo' => $p->titulo,
                'area' => $p->area?->nome,
                'categoria' => $p->categoria?->label(),
                'avaliacoes' => $total,
                'media' => round($concluidas->avg('nota'), 1),
                'medias_quesitos' => [
                    'video' => round($concluidas->avg('nota_video'), 1),
                    'resumo' => round($concluidas->avg('nota_resumo'), 1),
                    'pesquisa' => round($concluidas->avg('nota_pesquisa'), 1),
                    // Só os projetos com documento de continuação têm esse quesito.
                    'continuidade' => $this->media($concluidas, 'nota_continuidade'),
                ],
                'completo' => $total >= StatusAvaliacao::MIN_POR_PROJETO,
                'nota_maxima' => Avaliacao::notaMaxima(),
            ];
        })->all();

        usort($lista, fn ($a, $b) => [$b['media'], $b['avaliacoes'], $a['titulo']]
            <=> [$a['media'], $a['avaliacoes'], $b['titulo']]);

        // Posição atribuída depois da ordenação; empate na média divide o lugar.
        $posicao = 0;
        $anterior = null;
        foreach ($lista as $i => &$linha) {
            if ($linha['media'] !== $anterior) {
                $posicao = $i + 1;
                $anterior = $linha['media'];
            }
            $linha['posicao'] = $posicao;
        }

        return $lista;
    }

    /**
     * Média de um quesito contando só quem o preencheu — null quando nenhuma
     * avaliação o tem (caso do quesito de continuidade em projeto comum).
     *
     * @param  Collection<int, Avaliacao>  $avaliacoes
     */
    private function media(Collection $avaliacoes, string $coluna): ?float
    {
        $preenchidas = $avaliacoes->whereNotNull($coluna);

        return $preenchidas->isEmpty() ? null : round($preenchidas->avg($coluna), 1);
    }

    /**
     * Designa um projeto submetido para avaliação, criando avaliações "designadas".
     * Alvo: um avaliador específico, ou todos os avaliadores de uma área/subárea.
     * Pula quem já tem esse projeto e pode exceder o teto de 5 (override do admin).
     *
     * @return int quantas designações novas foram criadas
     */
    public function designar(Projeto $projeto, string $tipo, int $alvoId): int
    {
        $avaliadorIds = match ($tipo) {
            'avaliador' => [$alvoId],
            'area' => AvaliadorProfile::where('area_id', $alvoId)->pluck('user_id')->all(),
            'subarea' => AvaliadorProfile::where('subarea_id', $alvoId)->pluck('user_id')->all(),
            default => [],
        };

        $novas = 0;
        foreach ($avaliadorIds as $uid) {
            $avaliacao = Avaliacao::firstOrCreate(
                ['projeto_id' => $projeto->id, 'avaliador_id' => $uid],
                ['status' => StatusAvaliacao::Designada],
            );

            if ($avaliacao->wasRecentlyCreated) {
                $novas++;
            }
        }

        return $novas;
    }

    /** Define (ou remove, com null) o limite individual de avaliações do avaliador. */
    public function definirLimite(User $avaliador, ?int $limite): void
    {
        $avaliador->avaliadorProfile?->update(['limite_avaliacoes' => $limite]);
    }

    /** Marca/desmarca um avaliador como "demo" (fora do escopo real). */
    public function definirDemo(User $avaliador, bool $demo): void
    {
        $avaliador->update(['is_demo' => $demo]);
    }

    /** Apaga todas as avaliações dos avaliadores demo. Retorna quantas foram apagadas. */
    public function limparDadosDeTeste(): int
    {
        $demoIds = User::where('role', Role::Avaliador->value)
            ->where('is_demo', true)
            ->pluck('id');

        return Avaliacao::whereIn('avaliador_id', $demoIds)->delete();
    }

    /** Configuração da liberação da avaliação (data + se já liberada). */
    public function config(): array
    {
        $edicao = Edicao::atual();
        $data = $edicao?->avaliacao_liberada_em; // Carbon no fuso do app

        return [
            'liberada' => (bool) $edicao?->avaliacaoLiberada(),
            // Valor para <input type="datetime-local"> e rótulo dd/MM/aaaa HH:mm,
            // ambos no fuso do app (evita o shift de UTC do navegador).
            'liberada_em_input' => $data?->format('Y-m-d\TH:i'),
            'liberada_em_label' => $data?->format('d/m/Y H:i'),
        ];
    }

    /** Define a data de liberação (ou remove, com null) na edição atual. */
    public function definirLiberacao(?string $data): array
    {
        // A data chega como "hora de parede" local (ex.: 2026-08-17T07:00) e é
        // interpretada no fuso do app — 07:00 é 07:00 em Campo Grande, sem shift.
        $valor = ($data !== null && $data !== '')
            ? Carbon::parse($data, config('app.timezone'))
            : null;

        Edicao::atual()?->update(['avaliacao_liberada_em' => $valor]);

        return $this->config();
    }

    /** Ordena os grupos por nome da área (mantendo "Sem área" no fim). */
    private function ordenarPorArea(array $grupos): array
    {
        $lista = array_values($grupos);
        usort($lista, function ($a, $b) {
            if ($a['area_id'] === 0) {
                return 1;
            }
            if ($b['area_id'] === 0) {
                return -1;
            }

            return strcmp($a['area'], $b['area']);
        });

        return $lista;
    }
}
