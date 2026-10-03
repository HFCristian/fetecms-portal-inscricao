<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\ProjetoStatus;
use App\Enums\StatusAvaliacao;
use App\Enums\TipoRegistro;
use App\Models\Area;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use App\Support\Cota;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lista final da feira (Avaliação Online → Ranking dos projetos): o recorte dos
 * projetos que vão para a programação, em TXT.
 *
 * Duas etapas independentes:
 *
 * 1. SELEÇÃO — desce o ranking (média das notas finais, do melhor para o pior)
 *    e vai pegando quem cabe nas cotas que o admin definiu. As cotas são
 *    ANINHADAS: um total geral, dentro dele a cota de cada **categoria**,
 *    dentro dela a cota de cada **área** e, dentro da área, quantas vagas ficam
 *    reservadas ao **interior** (cidade que não é a capital do estado). Cada
 *    uma pode ser número fixo ou porcentagem do recorte que a contém — é o
 *    "100 da FUNDECT, 20 para agrárias, 70% desses para o interior". A reserva
 *    do interior só existe na **FETECMS FUNDECT** — é exigência do fomento
 *    dela; nas demais categorias a lista é só por nota.
 *
 *    Cota em branco não limita nada; cota 0 deixa o recorte de fora. A reserva
 *    do interior é PISO, não teto: se não houver projeto do interior suficiente,
 *    as vagas que sobram voltam para os demais numa segunda passada — a área
 *    nunca entrega menos do que sua cota por causa da reserva.
 *
 * 2. ORDENAÇÃO — o arquivo NÃO sai na ordem da nota: sai por categoria
 *    (FETECMS, FETEC Jr, FETECMS FUNDECT), depois por área em ordem alfabética
 *    e, dentro de cada categoria+área, por título. A numeração 001, 002…
 *    reinicia a cada par categoria+área e é gerada na hora da exportação.
 */
class ListaFinalService
{
    /** Sigla de projeto sem categoria ou sem área — visível de propósito, para o admin corrigir. */
    private const SIGLA_AUSENTE = 'SEM';

    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /**
     * Projetos elegíveis e quantos há em cada recorte, para a tela sugerir
     * cotas que existem.
     *
     * @return array<string, mixed>
     */
    public function opcoes(): array
    {
        $projetos = $this->avaliados();
        $areas = Area::query()->orderBy('nome')->get(['id', 'nome', 'sigla']);

        $porCategoria = $projetos->groupBy(fn (Projeto $p) => $p->categoria?->value ?? '');

        return [
            'total_disponivel' => $projetos->count(),
            'interior_disponivel' => $projetos->filter(fn (Projeto $p) => $this->doInterior($p))->count(),
            // As áreas saem DENTRO de cada categoria: a cota da área é uma
            // fatia da cota da categoria, e a do interior, uma fatia da área.
            'categorias' => array_map(function (Categoria $c) use ($porCategoria, $areas) {
                $daCategoria = $porCategoria->get($c->value, collect());
                $porArea = $daCategoria->groupBy(fn (Projeto $p) => $p->area_id ?? 0);

                return [
                    'value' => $c->value,
                    'label' => $c->label(),
                    'sigla' => $c->sigla(),
                    'disponiveis' => $daCategoria->count(),
                    // Só a FUNDECT reserva vaga para o interior (regra do fomento).
                    'permite_interior' => $c->permiteCotaInterior(),
                    'areas' => $areas->map(function (Area $a) use ($porArea) {
                        $daArea = $porArea->get($a->id, collect());

                        return [
                            'id' => $a->id,
                            'nome' => $a->nome,
                            'sigla' => $a->siglaDaLista(),
                            'disponiveis' => $daArea->count(),
                            'interior_disponiveis' => $daArea->filter(fn (Projeto $p) => $this->doInterior($p))->count(),
                        ];
                    })->values()->all(),
                ];
            }, Categoria::ordemDaLista()),
        ];
    }

    /**
     * Os projetos da lista, já ordenados e numerados.
     *
     * @param  array<string, mixed>  $cotas  ver selecionar()
     * @return list<array<string, mixed>>
     */
    public function gerar(array $cotas): array
    {
        $selecionados = $this->selecionar($this->avaliados(), $cotas);

        return $this->numerar($this->ordenar($selecionados));
    }

    /**
     * O TXT da lista: um bloco por projeto, separados por linha em branco.
     *
     * @param  array<string, mixed>  $cotas  ver selecionar()
     */
    public function exportarTxt(array $cotas): string
    {
        return $this->blocos($this->gerar($cotas));
    }

    /**
     * O texto do arquivo a partir dos itens já numerados: um bloco por projeto,
     * separados por linha em branco.
     *
     * @param  list<array<string, mixed>>  $itens
     */
    private function blocos(array $itens): string
    {
        $blocos = array_map(function (array $item) {
            $linhas = [
                "{$item['codigo']} - {$item['titulo']}",
                $item['escola'],
                ...$item['alunos'],
            ];

            if ($item['orientador'] !== null) {
                $linhas[] = $item['orientador'].' - Orientador(a)';
            }

            // O coorientador é opcional e vem logo abaixo do orientador — no
            // evento, os dois sobem ao estande.
            if ($item['coorientador'] !== null) {
                $linhas[] = $item['coorientador'].' - Coorientador(a)';
            }

            return implode("\n", $linhas);
        }, $itens);

        return implode("\n\n", $blocos)."\n";
    }

    /**
     * Gera o recorte da **classificação** (as cotas do Ranking) e o registra como
     * **rascunho** de uma lista preliminar ou final (Sprint 164).
     *
     * O rascunho é a lista antes de gerada: o admin inclui e retira projetos à
     * vontade, sem justificativa — ninguém é finalista por causa dele, então
     * revisar um recorte não mexe no credenciamento nem no mapa do evento.
     * Gerar ({@see self::gerarLista()}) é o que fecha.
     *
     * @param  array<string, mixed>  $cotas
     */
    public function rascunhar(array $cotas, User $admin, ?string $nome = null, string $tipo = ListaFinal::TIPO_FINAL): ListaFinal
    {
        $edicao = $this->edicaoOuFalha();
        $itens = $this->gerar($cotas);

        return DB::transaction(function () use ($cotas, $admin, $nome, $edicao, $itens, $tipo) {
            $lista = ListaFinal::create([
                'edicao_id' => $edicao->id,
                'nome' => trim((string) $nome) !== '' ? trim((string) $nome) : $this->nomePadrao($edicao, $tipo),
                'tipo' => $tipo,
                'vigente' => false,
                'rascunho' => true,
                'versao' => 1,
                'cotas' => $cotas,
                'gerada_por' => $admin->id,
            ]);

            $lista->projetos()->attach(
                collect($itens)->mapWithKeys(fn (array $i) => [$i['projeto_id'] => ['manual' => false]])->all(),
            );

            return $lista->fresh();
        });
    }

    /**
     * Rascunho de lista **final** montado pela **união de preliminares**
     * escolhidas (Sprint 164): todos os projetos delas, sem repetir. Só entram
     * preliminares já **geradas** — a que ainda é rascunho está sendo revista e
     * pode mudar. `origens` guarda de onde a final veio.
     *
     * @param  list<int>  $preliminarIds
     */
    public function rascunharDePreliminares(array $preliminarIds, User $admin, ?string $nome = null): ListaFinal
    {
        $edicao = $this->edicaoOuFalha();

        $preliminares = ListaFinal::query()
            ->whereIn('id', $preliminarIds)
            ->where('edicao_id', $edicao->id)
            ->where('tipo', ListaFinal::TIPO_PRELIMINAR)
            ->where('rascunho', false)
            ->where('demo', false)
            ->get();

        if ($preliminares->isEmpty() || $preliminares->count() !== count(array_unique($preliminarIds))) {
            throw ValidationException::withMessages([
                'preliminares' => 'Escolha listas preliminares já geradas nesta edição.',
            ]);
        }

        $projetos = DB::table('lista_final_projetos')
            ->whereIn('lista_final_id', $preliminares->pluck('id'))
            ->distinct()
            ->pluck('projeto_id')
            ->all();

        return DB::transaction(function () use ($edicao, $admin, $nome, $preliminares, $projetos) {
            $lista = ListaFinal::create([
                'edicao_id' => $edicao->id,
                'nome' => trim((string) $nome) !== '' ? trim((string) $nome) : $this->nomePadrao($edicao, ListaFinal::TIPO_FINAL),
                'tipo' => ListaFinal::TIPO_FINAL,
                'vigente' => false,
                'rascunho' => true,
                'versao' => 1,
                'origens' => $preliminares->map(fn (ListaFinal $l) => ['id' => $l->id, 'nome' => $l->nome])->values()->all(),
                'gerada_por' => $admin->id,
            ]);

            $lista->projetos()->attach(
                collect($projetos)->mapWithKeys(fn ($id) => [$id => ['manual' => false]])->all(),
            );

            return $lista->fresh();
        });
    }

    private function edicaoOuFalha(): Edicao
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'total' => 'Nenhuma edição em curso para gerar a lista.',
            ]);
        }

        return $edicao;
    }

    /**
     * **Gera** a lista: o rascunho revisado fecha (Sprint 164).
     *
     * - **Preliminar**: só deixa de ser rascunho. Várias convivem, e nenhuma
     *   define finalista.
     * - **Final**: vira a **ativa** da edição — a que vale para credenciamento,
     *   mapa, crachás, avaliação presencial e cerimônia —, e a final que estava
     *   ativa passa a inativa (fica no histórico e pode ser reativada).
     *
     * A composição gerada é a da tela, com o que o admin acrescentou ou retirou
     * na revisão. Daqui em diante cada mudança pede justificativa e sobe a
     * versão.
     */
    public function gerarLista(ListaFinal $lista, User $admin): ListaFinal
    {
        if (! $lista->rascunho) {
            throw ValidationException::withMessages([
                'lista' => 'Esta lista já foi gerada.',
            ]);
        }

        if ($lista->projetos()->count() === 0) {
            throw ValidationException::withMessages([
                'lista' => 'Uma lista sem nenhum projeto não pode ser gerada.',
            ]);
        }

        return DB::transaction(function () use ($lista, $admin) {
            $projetos = $lista->projetos()->count().' projeto(s)';

            if ($lista->ehPreliminar()) {
                $lista->forceFill(['rascunho' => false, 'gerada_em' => now()])->save();

                $this->registros->listaFinal(
                    TipoRegistro::ListaPreliminarGerada, $admin, $lista->nome, null, null, $projetos,
                );

                return $lista->fresh();
            }

            $this->desativarFinais($lista);
            $lista->forceFill(['vigente' => true, 'rascunho' => false, 'gerada_em' => now()])->save();

            $this->registros->listaFinal(
                TipoRegistro::ListaFinalOficializada, $admin, $lista->nome, null, null, $projetos,
            );

            return $lista->fresh();
        });
    }

    /** O nome antigo do mesmo ato, para quem ainda chama `publicar`. */
    public function publicar(ListaFinal $lista, User $admin): ListaFinal
    {
        return $this->gerarLista($lista, $admin);
    }

    /**
     * Volta a ativar uma lista final que já foi a ativa (Sprint 164). Trocar a
     * ativa muda quem é finalista no meio da etapa presencial, então pede
     * **justificativa** e entra em Registros → Lista final.
     */
    public function reativar(ListaFinal $lista, User $admin, string $justificativa): ListaFinal
    {
        if (! $lista->ehFinal() || $lista->rascunho) {
            throw ValidationException::withMessages([
                'lista' => 'Só uma lista final já gerada pode ser a ativa.',
            ]);
        }

        if ($lista->vigente) {
            throw ValidationException::withMessages([
                'lista' => 'Esta lista já é a final ativa.',
            ]);
        }

        return DB::transaction(function () use ($lista, $admin, $justificativa) {
            $anterior = ListaFinal::vigente($lista->edicao, $lista->demo);
            $this->desativarFinais($lista);
            $lista->forceFill(['vigente' => true])->save();

            $this->registros->listaFinal(
                TipoRegistro::ListaFinalReativada, $admin, $lista->nome, null, trim($justificativa),
                ($anterior ? "no lugar de {$anterior->nome}" : 'nenhuma estava ativa'),
            );

            return $lista->fresh();
        });
    }

    /** Desliga a final ativa da edição (na mesma trilha: oficial ou demo). */
    private function desativarFinais(ListaFinal $lista): void
    {
        ListaFinal::where('edicao_id', $lista->edicao_id)
            ->where('demo', $lista->demo)
            ->where('tipo', ListaFinal::TIPO_FINAL)
            ->update(['vigente' => false]);
    }

    /**
     * As listas registradas na edição em curso — rascunhos e oficiais —, da
     * mais nova para a mais antiga.
     *
     * Os rascunhos aparecem junto de propósito: uma prévia que ficou pelo
     * caminho precisa ser encontrável, senão vira lixo invisível no banco.
     *
     * @return list<array<string, mixed>>
     */
    public function listasOficiais(): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        return ListaFinal::where('edicao_id', $edicao->id)
            // A lista de demonstração não é histórico oficial: ela existe só
            // para o balcão em modo de teste.
            ->where('demo', false)
            ->with('autor:id,name')
            ->withCount('projetos')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ListaFinal $l) => [
                'id' => $l->id,
                'nome' => $l->nome,
                'tipo' => $l->tipo,
                'vigente' => $l->vigente,
                'rascunho' => $l->rascunho,
                'versao' => $l->versao,
                'projetos' => $l->projetos_count,
                'origens' => $l->origens ?? [],
                'gerada_em' => $l->gerada_em?->toIso8601String(),
                'gerada_por' => $l->autor?->name,
                'criada_em' => $l->created_at?->toIso8601String(),
                'atualizada_em' => $l->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Os itens de uma lista **já registrada** — ordenados e numerados na hora,
     * a partir da composição gravada. É o que o TXT de uma lista oficial
     * exporta, e o que muda de conteúdo quando o admin altera a composição.
     *
     * @return list<array<string, mixed>>
     */
    public function itensDaLista(ListaFinal $lista): array
    {
        $ids = $lista->projetos()->pluck('projetos.id')->all();

        if ($ids === []) {
            return [];
        }

        $projetos = $this->avaliados()->whereIn('id', $ids);

        // Projeto que entrou à mão pode não ter avaliação concluída: ele não
        // aparece em avaliados(), então é buscado à parte.
        $faltantes = array_diff($ids, $projetos->pluck('id')->all());

        if ($faltantes !== []) {
            $projetos = $projetos->concat($this->porIds($faltantes));
        }

        $itens = $this->numerar($this->ordenar($projetos->values()->all()));

        return $this->aplicarCodigosFixos($lista, $itens);
    }

    /**
     * Troca o código calculado pelo **fixado** (Sprint 159), onde houver, e
     * reordena cada grupo categoria+área pelo código — um projeto que entrou
     * depois do envio ganha o último número do grupo e aparece no fim dele, e
     * não no meio da ordem alfabética com um número fora de sequência.
     *
     * @param  list<array<string, mixed>>  $itens
     * @return list<array<string, mixed>>
     */
    private function aplicarCodigosFixos(ListaFinal $lista, array $itens): array
    {
        $fixos = $this->codigosFixos($lista);

        if ($fixos === []) {
            return $itens;
        }

        $grupos = [];
        foreach ($itens as $i => $item) {
            if (isset($fixos[$item['projeto_id']])) {
                $itens[$i]['codigo'] = $fixos[$item['projeto_id']];
            }
            $grupos[$item['categoria'].'|'.$item['area']] ??= count($grupos);
        }

        usort($itens, fn (array $a, array $b) => [$grupos[$a['categoria'].'|'.$a['area']], $a['codigo']]
            <=> [$grupos[$b['categoria'].'|'.$b['area']], $b['codigo']]);

        return $itens;
    }

    /** @return array<int, string> projeto_id => código fixado */
    private function codigosFixos(ListaFinal $lista): array
    {
        return DB::table('lista_final_projetos')
            ->where('lista_final_id', $lista->id)
            ->whereNotNull('codigo')
            ->pluck('codigo', 'projeto_id')
            ->map(fn ($c) => (string) $c)
            ->all();
    }

    /**
     * **Fixa** o código de cada projeto da lista (Sprint 159): até aqui ele era
     * recalculado a cada leitura, e incluir um projeto empurrava o número dos
     * que vinham depois. É o passo que antecede mandar o código por e-mail —
     * depois disso, o número de uma equipe não muda mais.
     *
     * Idempotente: o que já está fixado fica; um projeto sem código (não
     * deveria haver) ganha o próximo número livre do grupo.
     *
     * @return int quantos códigos foram gravados agora
     */
    public function fixarCodigos(ListaFinal $lista): int
    {
        return DB::transaction(function () use ($lista) {
            $fixos = $this->codigosFixos($lista);
            $gravados = 0;

            foreach ($this->itensDaLista($lista) as $item) {
                if (isset($fixos[$item['projeto_id']])) {
                    continue;
                }

                $codigo = $fixos === []
                    ? $item['codigo']
                    : $this->proximoCodigo($lista, Projeto::find($item['projeto_id']));

                DB::table('lista_final_projetos')
                    ->where('lista_final_id', $lista->id)
                    ->where('projeto_id', $item['projeto_id'])
                    ->update(['codigo' => $codigo]);
                $gravados++;
            }

            if ($lista->codigos_congelados_em === null) {
                $lista->update(['codigos_congelados_em' => now()]);
            }

            return $gravados;
        });
    }

    /**
     * O próximo número livre do grupo categoria+área do projeto, numa lista
     * com códigos já fixados: o maior que existe + 1.
     */
    private function proximoCodigo(ListaFinal $lista, ?Projeto $projeto): string
    {
        $par = ($projeto?->categoria?->sigla() ?? self::SIGLA_AUSENTE)
            .'.'.($projeto?->area?->siglaDaLista() ?? self::SIGLA_AUSENTE);

        $maior = collect($this->codigosFixos($lista))
            ->filter(fn (string $c) => str_starts_with($c, $par.'-'))
            ->map(fn (string $c) => (int) substr($c, strlen($par) + 1))
            ->max() ?? 0;

        return $par.'-'.str_pad((string) ($maior + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * O código de cada projeto da lista (FET.AGR-001), por id do projeto.
     *
     * @return array<int, string>
     */
    public function codigosDaLista(ListaFinal $lista): array
    {
        return array_column($this->itensDaLista($lista), 'codigo', 'projeto_id');
    }

    /**
     * Acrescenta um projeto à lista oficial. Sobe a versão (o arquivo baixado
     * depois é outro) e entra na trilha com a justificativa — é uma decisão
     * fora do recorte por nota, então precisa ficar explicada.
     */
    public function adicionarProjeto(ListaFinal $lista, Projeto $projeto, User $admin, ?string $justificativa = null): ListaFinal
    {
        if ($lista->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto já está na lista.',
            ]);
        }

        // Rascunho (Sprint 164): edição livre, antes de a lista existir de fato.
        if ($lista->rascunho) {
            $lista->projetos()->attach($projeto->id, ['manual' => true]);

            return $lista->fresh();
        }

        $this->exigirJustificativa($justificativa);

        return DB::transaction(function () use ($lista, $projeto, $admin, $justificativa) {
            // Lista com códigos já enviados: quem entra ganha o próximo número
            // livre do grupo, sem mexer no de ninguém.
            $codigo = $lista->codigos_congelados_em !== null ? $this->proximoCodigo($lista, $projeto) : null;

            $lista->projetos()->attach($projeto->id, ['manual' => true, 'codigo' => $codigo]);
            $lista->increment('versao');

            $this->registros->listaFinal(
                TipoRegistro::ListaFinalProjetoAdicionado, $admin, $lista->nome, $projeto, trim($justificativa),
            );

            return $lista->fresh();
        });
    }

    /** Retira um projeto da lista oficial, com justificativa, e sobe a versão. */
    public function removerProjeto(ListaFinal $lista, Projeto $projeto, User $admin, ?string $justificativa = null): ListaFinal
    {
        if (! $lista->projetos()->whereKey($projeto->id)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto não está na lista.',
            ]);
        }

        if ($lista->rascunho) {
            $lista->projetos()->detach($projeto->id);

            return $lista->fresh();
        }

        $this->exigirJustificativa($justificativa);

        return DB::transaction(function () use ($lista, $projeto, $admin, $justificativa) {
            $lista->projetos()->detach($projeto->id);
            $lista->increment('versao');

            $this->registros->listaFinal(
                TipoRegistro::ListaFinalProjetoRemovido, $admin, $lista->nome, $projeto, trim($justificativa),
            );

            return $lista->fresh();
        });
    }

    /** Depois de gerada, mudar a lista é decisão que precisa ficar explicada. */
    private function exigirJustificativa(?string $justificativa): void
    {
        if (mb_strlen(trim((string) $justificativa)) < 5) {
            throw ValidationException::withMessages([
                'justificativa' => 'Explique a alteração (mínimo de 5 caracteres): a lista já foi gerada.',
            ]);
        }
    }

    /**
     * A lista aberta para edição: a composição atual e os projetos que ainda
     * podem entrar — **qualquer projeto submetido** (Sprint 164), avaliado ou
     não (o de cadastro manual, por exemplo).
     *
     * @return array<string, mixed>
     */
    public function detalhar(ListaFinal $lista): array
    {
        $itens = $this->itensDaLista($lista);
        $dentro = array_column($itens, 'projeto_id');
        $manuais = $lista->projetos()->pluck('lista_final_projetos.manual', 'projetos.id');

        return [
            'lista' => [
                'id' => $lista->id,
                'nome' => $lista->nome,
                'tipo' => $lista->tipo,
                'vigente' => $lista->vigente,
                'rascunho' => $lista->rascunho,
                'demo' => (bool) $lista->demo,
                'versao' => $lista->versao,
                'projetos' => count($itens),
                'origens' => $lista->origens ?? [],
                'gerada_em' => $lista->gerada_em?->toIso8601String(),
            ],
            'itens' => array_map(fn (array $i) => $i + ['manual' => (bool) ($manuais[$i['projeto_id']] ?? false)], $itens),
            // Candidatos: qualquer submetido que está de fora — os avaliados
            // primeiro (com a média), depois os sem avaliação.
            'candidatos' => $this->candidatos($dentro)
                ->map(fn (Projeto $p) => [
                    'id' => $p->id,
                    'titulo' => $p->titulo,
                    'categoria' => $p->categoria?->label(),
                    'area' => $p->area?->nome,
                    'media' => $p->media_nota === null ? null : round((float) $p->media_nota, 2),
                ])
                ->values()
                ->all(),
        ];
    }

    /** O TXT de uma lista oficial já registrada. */
    public function exportarTxtDaLista(ListaFinal $lista): string
    {
        return $this->blocos($this->itensDaLista($lista));
    }

    /** Nome sugerido quando o admin não informa um. */
    private function nomePadrao(Edicao $edicao, string $tipo = ListaFinal::TIPO_FINAL): string
    {
        return ($tipo === ListaFinal::TIPO_PRELIMINAR ? 'Lista preliminar' : 'Lista final').' · '.$edicao->nome;
    }

    /**
     * Os projetos submetidos que ainda não estão na lista: os avaliados (com a
     * média) e os sem avaliação concluída, como os de cadastro manual.
     *
     * @param  list<int>  $dentro
     * @return Collection<int, Projeto>
     */
    private function candidatos(array $dentro): Collection
    {
        $avaliados = $this->avaliados()->reject(fn (Projeto $p) => in_array($p->id, $dentro, true));

        $semAvaliacao = Projeto::semDemo()
            ->where('status', ProjetoStatus::Submetido->value)
            ->whereNotIn('id', [...$dentro, ...$avaliados->pluck('id')->all()])
            ->with('area:id,nome')
            ->orderBy('titulo')
            ->get();

        return $avaliados->values()->concat($semAvaliacao);
    }

    /**
     * Projetos por id, com as mesmas relações de avaliados() — para os que
     * entraram na lista sem avaliação concluída.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Projeto>
     */
    private function porIds(array $ids): Collection
    {
        return Projeto::query()
            ->whereIn('id', $ids)
            ->withAvg(
                ['avaliacoes as media_nota' => fn ($q) => $q->considerada()
                    ->where('status', StatusAvaliacao::Concluida->value)],
                'nota',
            )
            ->with([
                'area:id,nome,sigla',
                'user:id,name',
                'alunos:id,projeto_id,nome',
                'coorientador:id,projeto_id,nome',
                'instituicao:id,nome,cidade_id',
                'instituicao.cidade:id,nome,estado_id,capital',
                'instituicao.cidade.estado:id,uf',
                'cidade:id,nome,capital',
                'estado:id,uf',
            ])
            ->get();
    }

    /**
     * Projetos com ao menos uma avaliação concluída, do melhor para o pior —
     * o mesmo universo e a mesma ordem do Ranking dos projetos.
     *
     * @return Collection<int, Projeto>
     */
    private function avaliados(): Collection
    {
        // Projeto de orientador demo não disputa vaga na feira.
        return Projeto::semDemo()
            ->whereHas('avaliacoes', fn ($q) => $q->considerada()
                ->where('status', StatusAvaliacao::Concluida->value))
            ->withAvg(
                ['avaliacoes as media_nota' => fn ($q) => $q->considerada()
                    ->where('status', StatusAvaliacao::Concluida->value)],
                'nota',
            )
            ->withCount(['avaliacoes as concluidas_count' => fn ($q) => $q->considerada()
                ->where('status', StatusAvaliacao::Concluida->value)])
            ->with([
                'area:id,nome,sigla',
                'user:id,name',
                'alunos:id,projeto_id,nome',
                'coorientador:id,projeto_id,nome',
                'instituicao:id,nome,cidade_id',
                'instituicao.cidade:id,nome,estado_id,capital',
                'instituicao.cidade.estado:id,uf',
                'cidade:id,nome,capital',
                'estado:id,uf',
            ])
            ->get()
            // Empate na média cai para quem tem mais avaliações e depois título.
            ->sortBy([
                fn (Projeto $a, Projeto $b) => (float) $b->media_nota <=> (float) $a->media_nota,
                fn (Projeto $a, Projeto $b) => $b->concluidas_count <=> $a->concluidas_count,
                fn (Projeto $a, Projeto $b) => strcmp($this->chave($a->titulo), $this->chave($b->titulo)),
            ])
            ->values();
    }

    /**
     * Desce o ranking pegando quem ainda cabe nas cotas aninhadas.
     *
     * O payload é
     * `['total' => cota, 'categorias' => ['fetecms' => ['cota' => cota,
     * 'areas' => [7 => ['cota' => cota, 'interior' => cota]]]]]`, onde cada
     * `cota` é `['tipo' => 'fixo'|'percentual', 'valor' => n]` ou null.
     *
     * São duas passadas: a primeira respeita a reserva do interior (um projeto
     * da capital não ocupa vaga reservada); a segunda devolve aos demais as
     * vagas que o interior não preencheu, para a cota da área nunca render
     * menos do que foi pedido.
     *
     * @param  Collection<int, Projeto>  $projetos
     * @param  array<string, mixed>  $cotas
     * @return list<Projeto>
     */
    private function selecionar(Collection $projetos, array $cotas): array
    {
        $limites = $this->resolverCotas($projetos, $cotas);

        $usado = ['total' => 0, 'categoria' => [], 'area' => [], 'capital' => []];
        $escolhidos = [];
        $adiados = [];

        foreach ($projetos as $projeto) {
            $encaixe = $this->encaixe($projeto, $limites, $usado, true);

            if ($encaixe === 'cabe') {
                $this->contabilizar($projeto, $usado);
                $escolhidos[] = $projeto;
            } elseif ($encaixe === 'reservado') {
                // Vaga existe, mas está guardada para o interior. Se sobrar, ele
                // volta na segunda passada.
                $adiados[] = $projeto;
            }
        }

        foreach ($adiados as $projeto) {
            if ($this->encaixe($projeto, $limites, $usado, false) === 'cabe') {
                $this->contabilizar($projeto, $usado);
                $escolhidos[] = $projeto;
            }
        }

        return $escolhidos;
    }

    /**
     * Traduz as cotas do formulário em número de vagas, de fora para dentro:
     * a porcentagem da categoria é sobre o total, a da área sobre a categoria e
     * a do interior sobre a área. Sem cota acima, a base é quantos projetos
     * elegíveis existem naquele recorte.
     *
     * @param  Collection<int, Projeto>  $projetos
     * @param  array<string, mixed>  $cotas
     * @return array{total:?int, categoria:array<string,int>, area:array<string,int>, interior:array<string,int>}
     */
    private function resolverCotas(Collection $projetos, array $cotas): array
    {
        $total = Cota::de($cotas['total'] ?? null)?->resolver($projetos->count());
        $baseCategoria = $total ?? $projetos->count();

        $limites = ['total' => $total, 'categoria' => [], 'area' => [], 'interior' => []];

        foreach ((array) ($cotas['categorias'] ?? []) as $categoria => $config) {
            $config = (array) $config;
            $daCategoria = $projetos->filter(fn (Projeto $p) => $p->categoria?->value === $categoria);

            $cotaCategoria = Cota::de($config['cota'] ?? null)?->resolver($baseCategoria);
            if ($cotaCategoria !== null) {
                $limites['categoria'][$categoria] = $cotaCategoria;
            }

            foreach ((array) ($config['areas'] ?? []) as $areaId => $areaConfig) {
                $areaConfig = (array) $areaConfig;
                $chave = $categoria.'|'.$areaId;

                $cotaArea = Cota::de($areaConfig['cota'] ?? null)
                    ?->resolver($cotaCategoria ?? $daCategoria->count());

                if ($cotaArea === null) {
                    // Sem cota na área não há vaga para reservar: a cota do
                    // interior só faz sentido dentro de um número fechado.
                    continue;
                }

                $limites['area'][$chave] = $cotaArea;

                // A reserva do interior só existe onde a categoria a permite —
                // hoje, a FETECMS FUNDECT. Nas outras, o campo é ignorado.
                if (! (Categoria::tryFrom((string) $categoria)?->permiteCotaInterior() ?? false)) {
                    continue;
                }

                $reserva = Cota::de($areaConfig['interior'] ?? null)?->resolver($cotaArea);
                if ($reserva !== null) {
                    $limites['interior'][$chave] = min($reserva, $cotaArea);
                }
            }
        }

        return $limites;
    }

    /**
     * O projeto cabe agora? Devolve 'cabe', 'reservado' (só a reserva do
     * interior barrou — pode voltar na segunda passada) ou 'nao'.
     *
     * @param  array{total:?int, categoria:array<string,int>, area:array<string,int>, interior:array<string,int>}  $limites
     * @param  array{total:int, categoria:array<string,int>, area:array<string,int>, capital:array<string,int>}  $usado
     */
    private function encaixe(Projeto $projeto, array $limites, array $usado, bool $respeitarReserva): string
    {
        if ($limites['total'] !== null && $usado['total'] >= $limites['total']) {
            return 'nao';
        }

        $categoria = $projeto->categoria?->value;
        if ($categoria !== null && isset($limites['categoria'][$categoria])
            && ($usado['categoria'][$categoria] ?? 0) >= $limites['categoria'][$categoria]) {
            return 'nao';
        }

        $chave = $categoria.'|'.(int) $projeto->area_id;
        if (! isset($limites['area'][$chave])) {
            return 'cabe';
        }

        if (($usado['area'][$chave] ?? 0) >= $limites['area'][$chave]) {
            return 'nao';
        }

        // Reserva do interior: um projeto da capital só ocupa as vagas que
        // sobram depois de guardadas as do interior.
        $reserva = $limites['interior'][$chave] ?? 0;
        if ($respeitarReserva && $reserva > 0 && ! $this->doInterior($projeto)) {
            $paraCapital = $limites['area'][$chave] - $reserva;

            if (($usado['capital'][$chave] ?? 0) >= $paraCapital) {
                return 'reservado';
            }
        }

        return 'cabe';
    }

    /**
     * @param  array{total:int, categoria:array<string,int>, area:array<string,int>, capital:array<string,int>}  $usado
     */
    private function contabilizar(Projeto $projeto, array &$usado): void
    {
        $categoria = $projeto->categoria?->value;
        $chave = $categoria.'|'.(int) $projeto->area_id;

        $usado['total']++;
        $usado['categoria'][$categoria] = ($usado['categoria'][$categoria] ?? 0) + 1;
        $usado['area'][$chave] = ($usado['area'][$chave] ?? 0) + 1;

        if (! $this->doInterior($projeto)) {
            $usado['capital'][$chave] = ($usado['capital'][$chave] ?? 0) + 1;
        }
    }

    /**
     * Projeto do interior: a cidade dele não é a capital do próprio estado.
     * Vale a cidade da escola, que é a que aparece na lista; sem escola, a do
     * projeto. Cidade desconhecida não conta como interior.
     */
    private function doInterior(Projeto $projeto): bool
    {
        $cidade = $projeto->instituicao?->cidade ?? $projeto->cidade;

        return $cidade !== null && ! $cidade->capital;
    }

    /**
     * Ordem do arquivo: categoria (FETECMS → FETEC Jr → FUNDECT), área em ordem
     * alfabética e título. Projeto sem categoria ou sem área vai para o fim do
     * seu grupo.
     *
     * @param  list<Projeto>  $projetos
     * @return list<Projeto>
     */
    private function ordenar(array $projetos): array
    {
        $ordemCategoria = array_flip(array_map(fn (Categoria $c) => $c->value, Categoria::ordemDaLista()));

        usort($projetos, function (Projeto $a, Projeto $b) use ($ordemCategoria) {
            $ca = $ordemCategoria[$a->categoria?->value] ?? PHP_INT_MAX;
            $cb = $ordemCategoria[$b->categoria?->value] ?? PHP_INT_MAX;

            return [$ca, $this->chave($a->area?->nome ?? 'zzz'), $this->chave($a->titulo)]
                <=> [$cb, $this->chave($b->area?->nome ?? 'zzz'), $this->chave($b->titulo)];
        });

        return $projetos;
    }

    /**
     * Monta a linha de cada projeto. O sequencial reinicia a cada par
     * categoria+área e é gerado agora, na criação da lista.
     *
     * @param  list<Projeto>  $projetos
     * @return list<array<string, mixed>>
     */
    private function numerar(array $projetos): array
    {
        $sequencias = [];

        return array_map(function (Projeto $projeto) use (&$sequencias) {
            $categoria = $projeto->categoria?->sigla() ?? self::SIGLA_AUSENTE;
            $area = $projeto->area?->siglaDaLista() ?? self::SIGLA_AUSENTE;
            $par = $categoria.'.'.$area;

            $sequencias[$par] = ($sequencias[$par] ?? 0) + 1;
            $numero = str_pad((string) $sequencias[$par], 3, '0', STR_PAD_LEFT);

            return [
                'projeto_id' => $projeto->id,
                'codigo' => $par.'-'.$numero,
                'titulo' => $projeto->titulo,
                'categoria' => $projeto->categoria?->label(),
                'area' => $projeto->area?->nome,
                'escola' => $this->escola($projeto),
                'alunos' => $projeto->alunos
                    ->sortBy(fn ($aluno) => $this->chave($aluno->nome))
                    ->pluck('nome')
                    ->values()
                    ->all(),
                'orientador' => $projeto->user?->name,
                'coorientador' => $projeto->coorientador?->nome,
                'media' => $projeto->media_nota === null ? null : round((float) $projeto->media_nota, 2),
            ];
        }, $projetos);
    }

    /**
     * A linha da escola: "Escola / Cidade - UF". Sem instituição cadastrada,
     * vale a localidade do próprio projeto.
     */
    private function escola(Projeto $projeto): string
    {
        $instituicao = $projeto->instituicao;

        $nome = $instituicao?->nome;
        $cidade = $instituicao?->cidade?->nome ?? $projeto->cidade?->nome ?? $projeto->cidade_nome;
        $uf = $instituicao?->cidade?->estado?->uf ?? $projeto->estado?->uf ?? $projeto->estado_nome;

        $local = trim(implode(' - ', array_filter([$cidade, $uf])));
        $partes = array_filter([$nome, $local === '' ? null : $local]);

        return $partes === [] ? '—' : implode(' / ', $partes);
    }

    /** Chave de ordenação tolerante a acento e caixa (pt-BR sem depender de collation). */
    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
