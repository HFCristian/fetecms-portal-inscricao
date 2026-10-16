<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\Turno;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\MapaLayout;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\FaixaEstandes;
use App\Support\PlantaEvento;
use App\Support\RegrasTurnos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mapa do Evento → **remanejar sem refazer** (Sprints 212–213).
 *
 * As listas de turnos e de estandes nascem de uma geração, e gerar de novo
 * substitui tudo — inclusive o que o admin arrumou à mão. Depois de impressas
 * e enviadas às equipes, refazer não é opção: a lista final ganha um projeto
 * (cadastro manual, inclusão com justificativa) e ele precisa de lugar, ou uma
 * equipe pede para trocar de horário com outra.
 *
 * Este serviço é o único caminho dessas mudanças, nas duas telas (Turnos e
 * planta). Um projeto vai para um **lugar** — turno + estande — e, quando o
 * estande já tem dono, o admin escolhe o que acontece com ele:
 *
 * - **trocar**: os dois trocam de lugar (estande e turno). Só existe para quem
 *   já tem lugar — o projeto novo não tem para onde mandar o outro;
 * - **empurrar**: o novo fica ali e cada ocupante anda um estande, na ordem da
 *   planta, até o primeiro estande vazio. Sem vazio até o fim, é recusado;
 * - **final**: o ocupante vai para o próximo estande livre depois do último
 *   ocupado do turno (estando o último ocupado no fim da planta, o primeiro
 *   livre do turno).
 *
 * "Estande ao lado" e "fim" seguem os **números da planta em vigor**, que é o
 * desenho físico: um número removido da planta não é lugar para ninguém.
 *
 * Antes dos estandes existirem a escolha é só de turno, como sempre foi. Com
 * eles distribuídos, mudar de turno **sem** estande deixaria o projeto com o
 * número do turno antigo — por isso o estande passa a ser obrigatório.
 *
 * A capacidade de cada turno continua sendo teto: o turno cheio recusa, e a
 * saída é aumentar a capacidade na configuração (que não regera nada).
 */
class RemanejamentoMapaService
{
    public const MODOS = ['trocar', 'empurrar', 'final'];

    public function __construct(
        private readonly RegistroAtividadeService $registros,
        private readonly TurnosApresentacaoService $turnos,
        private readonly EstandesProjetosService $estandes,
        private readonly ListaFinalService $listas,
    ) {}

    /**
     * O que está fora do lugar: finalista sem turno, finalista sem estande e
     * quem está no mapa mas saiu da lista final. Mais o que o diálogo de
     * destino precisa — os números da planta, a ocupação e a capacidade.
     *
     * @return array<string, mixed>
     */
    public function painel(): array
    {
        $edicao = Edicao::atual();
        $lista = $edicao === null ? null : ListaFinal::vigente($edicao);
        $idsLista = $lista === null ? collect() : $lista->projetos()->pluck('projetos.id');
        $codigos = $lista === null ? [] : $this->listas->codigosDaLista($lista);

        $turnos = $edicao === null ? collect() : TurnoApresentacao::where('edicao_id', $edicao->id)->get()->keyBy('projeto_id');
        $estandes = $edicao === null ? collect() : EstandeProjeto::where('edicao_id', $edicao->id)->get()->keyBy('projeto_id');
        $estandesGerados = $this->estandesGerados($edicao, $estandes);
        $regras = RegrasTurnos::deArray($edicao?->turnos_config);

        $idsSemTurno = $idsLista->reject(fn (int $id) => $turnos->has($id));
        $idsSemEstande = $estandesGerados
            ? $idsLista->filter(fn (int $id) => $turnos->has($id) && ! $estandes->has($id))
            : collect();
        $idsFora = $turnos->keys()->reject(fn (int $id) => $idsLista->contains($id))
            ->merge($estandes->keys()->reject(fn (int $id) => $idsLista->contains($id)))
            ->unique();

        $projetos = Projeto::whereIn('id', $idsSemTurno->merge($idsSemEstande)->merge($idsFora)->unique())
            ->with(['area:id,nome', 'instituicao:id,nome', 'user:id,name'])
            ->get()
            ->keyBy('id');

        $linha = function (int $id) use ($projetos, $turnos, $estandes, $codigos): ?array {
            $p = $projetos->get($id);

            return $p === null ? null : [
                'projeto_id' => $p->id,
                'titulo' => $p->titulo,
                'codigo' => $codigos[$p->id] ?? null,
                'categoria' => $p->categoria?->label(),
                'categoria_value' => $p->categoria?->value,
                'area' => $p->area?->nome,
                'escola' => $p->instituicao?->nome,
                'orientador' => $p->user?->name,
                'turno' => $turnos->get($id)?->turno?->value,
                'numero' => $estandes->get($id)?->numero,
            ];
        };

        $montar = fn (Collection $ids) => $ids->map($linha)->filter()->sortBy(fn ($l) => mb_strtolower($l['titulo']))->values()->all();

        return [
            'tem_lista' => $lista !== null,
            'turnos_gerados' => $turnos->isNotEmpty(),
            'estandes_gerados' => $estandesGerados,
            'capacidade' => $regras->capacidade(),
            'ocupacao_turnos' => [
                Turno::A->value => $turnos->filter(fn ($t) => $t->turno === Turno::A)->count(),
                Turno::B->value => $turnos->filter(fn ($t) => $t->turno === Turno::B)->count(),
            ],
            'numeros' => $this->numerosDaPlanta($edicao),
            'ocupacao' => $estandesGerados ? $this->estandes->porEstande() : [],
            'sem_turno' => $montar($idsSemTurno),
            'sem_estande' => $montar($idsSemEstande),
            'fora_da_lista' => $montar($idsFora),
        ];
    }

    /**
     * Põe um projeto num lugar (turno + estande) — incluí-lo no mapa ou
     * movê-lo, conforme ele já tenha lugar ou não.
     *
     * @return array<string, mixed> o painel novo, o que se mexeu e os avisos
     */
    public function alocar(int $projetoId, Turno $turno, ?int $numero, ?string $modo, User $admin): array
    {
        $edicao = $this->edicaoOuFalha();
        $lista = ListaFinal::vigente($edicao);

        if ($lista === null) {
            throw ValidationException::withMessages([
                'lista_final' => 'Não há lista final oficial vigente.',
            ]);
        }

        if (! $lista->projetos()->whereKey($projetoId)->exists()) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto não está na lista final vigente.',
            ]);
        }

        $projeto = Projeto::with('user')->findOrFail($projetoId);
        $atualTurno = TurnoApresentacao::where('edicao_id', $edicao->id)->where('projeto_id', $projetoId)->first();
        $atualEstande = EstandeProjeto::where('edicao_id', $edicao->id)->where('projeto_id', $projetoId)->first();
        $estandesGerados = $this->estandesGerados($edicao);

        if ($numero === null && $estandesGerados) {
            throw ValidationException::withMessages([
                'numero' => 'Os estandes já foram distribuídos: escolha também o estande do projeto.',
            ]);
        }

        if ($numero !== null && ! $estandesGerados) {
            throw ValidationException::withMessages([
                'numero' => 'Os estandes ainda não foram distribuídos: escolha só o turno.',
            ]);
        }

        $numeros = $this->numerosDaPlanta($edicao);

        if ($numero !== null && ! in_array($numero, $numeros, true)) {
            throw ValidationException::withMessages([
                'numero' => sprintf('O estande %s não existe na planta em vigor.', $this->rotulo($numero)),
            ]);
        }

        // Nada muda: o mesmo lugar.
        if ($atualTurno?->turno === $turno && ($numero === null || $atualEstande?->numero === $numero)) {
            return $this->resposta([], []);
        }

        $ocupante = $numero === null ? null : EstandeProjeto::with('projeto.user')
            ->where('edicao_id', $edicao->id)
            ->where('turno', $turno->value)
            ->where('numero', $numero)
            ->where('projeto_id', '!=', $projetoId)
            ->first();

        if ($ocupante !== null && ! in_array($modo, self::MODOS, true)) {
            throw ValidationException::withMessages([
                'modo' => sprintf(
                    'O estande %s do %s já é de "%s". Escolha: trocar os dois de lugar, empurrar a fila ou mandar o atual para o fim.',
                    $this->rotulo($numero),
                    $turno->label(),
                    $ocupante->projeto?->titulo,
                ),
            ]);
        }

        if ($ocupante !== null && $modo === 'trocar' && ($atualTurno === null || ($estandesGerados && $atualEstande === null))) {
            throw ValidationException::withMessages([
                'modo' => 'Este projeto ainda não tem lugar no mapa: não há o que trocar. Empurre a fila ou mande o atual para o fim.',
            ]);
        }

        // Capacidade: só conta quando o projeto ENTRA no turno — trocar mantém a
        // ocupação dos dois turnos igual.
        $entraNoTurno = $atualTurno?->turno !== $turno && ! ($ocupante !== null && $modo === 'trocar');

        if ($entraNoTurno) {
            $regras = RegrasTurnos::deArray($edicao->turnos_config);
            $ocupacao = TurnoApresentacao::where('edicao_id', $edicao->id)->where('turno', $turno->value)->count();

            if ($ocupacao >= $regras->capacidadeDe($turno)) {
                throw ValidationException::withMessages([
                    'turno' => sprintf(
                        'O %s já está com os %d estandes ocupados. Aumente a capacidade na configuração dos turnos ou tire outro projeto de lá antes.',
                        $turno->label(),
                        $regras->capacidadeDe($turno),
                    ),
                ]);
            }
        }

        $movimentos = [];
        $avisos = [];

        DB::transaction(function () use (
            $edicao, $projeto, $turno, $numero, $modo, $ocupante, $atualTurno, $atualEstande, $numeros, $admin,
            &$movimentos, &$avisos
        ) {
            $deX = $this->lugar($atualTurno?->turno, $atualEstande?->numero);

            // O lugar atual sai primeiro: libera o número para o empurrão e
            // para a troca, e a chave única (edição, turno, número) não esbarra.
            $origemTurno = $atualTurno?->turno;
            $origemNumero = $atualEstande?->numero;
            $atualEstande?->delete();

            if ($ocupante !== null) {
                $deY = $this->lugar($turno, $numero);

                $turnoY = $turno;

                if ($modo === 'trocar') {
                    $this->colocar($edicao, $ocupante->projeto, $origemTurno, $origemNumero);
                    $paraY = $this->lugar($origemTurno, $origemNumero);
                    $turnoY = $origemTurno;
                } elseif ($modo === 'empurrar') {
                    $deslocados = $this->empurrar($edicao, $turno, $numero, $numeros);

                    foreach ($deslocados as $d) {
                        $movimentos[] = $d;
                        $this->registros->estandeProjetoMovido($d['projeto'], $admin, $d['de'], $d['para']);
                    }

                    $ocupante = null; // já registrado entre os deslocados
                } else {
                    $destino = $this->numeroDoFim($edicao, $turno, $numero, $numeros);
                    $ocupante->update(['numero' => $destino, 'manual' => true]);
                    $paraY = $this->lugar($turno, $destino);
                }

                if ($ocupante !== null) {
                    $movimentos[] = ['projeto' => $ocupante->projeto, 'de' => $deY, 'para' => $paraY];
                    $this->registrarMovimento($ocupante->projeto, $admin, $deY, $paraY, $turno, $turnoY);
                }
            }

            $this->colocar($edicao, $projeto, $turno, $numero);
            $paraX = $this->lugar($turno, $numero);
            $movimentos[] = ['projeto' => $projeto, 'de' => $deX, 'para' => $paraX];

            if ($atualTurno === null) {
                $this->registros->mapaProjetoIncluido($projeto, $admin, $paraX);
            } else {
                $this->registrarMovimento($projeto, $admin, $deX, $paraX, $origemTurno, $turno);
            }

            $avisos = $this->avisos($edicao, $movimentos, $origemTurno, $turno, $projeto);
        });

        return $this->resposta($movimentos, $avisos);
    }

    /**
     * Tira o projeto do mapa — turno e estande. É a saída de quem deixou a
     * lista final; num finalista, ele volta a aparecer como "sem turno".
     *
     * @return array<string, mixed>
     */
    public function retirar(int $projetoId, User $admin): array
    {
        $edicao = $this->edicaoOuFalha();
        $turno = TurnoApresentacao::where('edicao_id', $edicao->id)->where('projeto_id', $projetoId)->first();
        $estande = EstandeProjeto::where('edicao_id', $edicao->id)->where('projeto_id', $projetoId)->first();

        if ($turno === null && $estande === null) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto não está no mapa.',
            ]);
        }

        $projeto = Projeto::with('user')->findOrFail($projetoId);
        $de = $this->lugar($turno?->turno ?? $estande?->turno, $estande?->numero);

        DB::transaction(function () use ($turno, $estande, $projeto, $admin, $de) {
            $estande?->delete();
            $turno?->delete();
            $this->registros->mapaProjetoRetirado($projeto, $admin, $de);
        });

        return $this->resposta([], []);
    }

    // --- Os três destinos do ocupante -------------------------------------

    /**
     * Empurra a fila a partir de `$numero`: cada ocupante anda para o próximo
     * número da planta, até o primeiro vazio. Anda de trás para frente, para
     * nenhum passo pisar num número ainda ocupado.
     *
     * @param  list<int>  $numeros
     * @return list<array{projeto: Projeto, de: string, para: string}>
     */
    private function empurrar(Edicao $edicao, Turno $turno, int $numero, array $numeros): array
    {
        $ocupados = EstandeProjeto::with('projeto.user')
            ->where('edicao_id', $edicao->id)
            ->where('turno', $turno->value)
            ->get()
            ->keyBy('numero');

        $inicio = array_search($numero, $numeros, true);
        $cadeia = [];

        for ($i = $inicio; $i < count($numeros); $i++) {
            if (! $ocupados->has($numeros[$i])) {
                break;
            }

            $cadeia[] = $i;
        }

        $ultimo = end($cadeia);

        if (! isset($numeros[$ultimo + 1])) {
            throw ValidationException::withMessages([
                'modo' => sprintf(
                    'Não há estande vazio depois do %s no %s para empurrar a fila. Mande o atual para o fim ou troque os dois de lugar.',
                    $this->rotulo($numero),
                    $turno->label(),
                ),
            ]);
        }

        $deslocados = [];

        foreach (array_reverse($cadeia) as $i) {
            $linha = $ocupados->get($numeros[$i]);
            $linha->update(['numero' => $numeros[$i + 1], 'manual' => true]);

            array_unshift($deslocados, [
                'projeto' => $linha->projeto,
                'de' => $this->lugar($turno, $numeros[$i]),
                'para' => $this->lugar($turno, $numeros[$i + 1]),
            ]);
        }

        return $deslocados;
    }

    /**
     * O "fim" do turno: o próximo número da planta depois do último ocupado;
     * estando o último ocupado no fim da planta, o primeiro número livre. O
     * número que o novo projeto vai ocupar não conta como livre.
     *
     * @param  list<int>  $numeros
     */
    private function numeroDoFim(Edicao $edicao, Turno $turno, int $reservado, array $numeros): int
    {
        $ocupados = EstandeProjeto::where('edicao_id', $edicao->id)
            ->where('turno', $turno->value)
            ->pluck('numero')
            ->flip();

        $maior = $ocupados->keys()->max();

        foreach ($numeros as $n) {
            if ($n > $maior && ! $ocupados->has($n) && $n !== $reservado) {
                return $n;
            }
        }

        foreach ($numeros as $n) {
            if (! $ocupados->has($n) && $n !== $reservado) {
                return $n;
            }
        }

        throw ValidationException::withMessages([
            'modo' => sprintf('Não há estande livre no %s para onde mandar o projeto atual.', $turno->label()),
        ]);
    }

    // --- Apoio -----------------------------------------------------------

    /** Grava turno e estande de um projeto — cria ou atualiza as duas linhas. */
    private function colocar(Edicao $edicao, Projeto $projeto, ?Turno $turno, ?int $numero): void
    {
        if ($turno === null) {
            return;
        }

        $linhaTurno = TurnoApresentacao::firstOrNew(['edicao_id' => $edicao->id, 'projeto_id' => $projeto->id]);
        $novo = ! $linhaTurno->exists;
        $linhaTurno->fill([
            'turno' => $turno->value,
            'manual' => true,
            'regra' => $novo ? 'manual' : $linhaTurno->regra,
        ])->save();

        if ($numero === null) {
            return;
        }

        EstandeProjeto::updateOrCreate(
            ['edicao_id' => $edicao->id, 'projeto_id' => $projeto->id],
            ['turno' => $turno->value, 'numero' => $numero, 'manual' => true],
        );
    }

    /** O registro de quem se moveu: de turno, de estande ou dos dois. */
    private function registrarMovimento(Projeto $projeto, User $admin, string $de, string $para, ?Turno $turnoDe, Turno $turnoPara): void
    {
        if ($turnoDe !== $turnoPara) {
            $this->registros->turnoProjetoMovido($projeto, $admin, $de, $para);

            return;
        }

        $this->registros->estandeProjetoMovido($projeto, $admin, $de, $para);
    }

    /**
     * O que o admin precisa saber depois: projeto empurrado para fora da faixa
     * da categoria e avaliação presencial já entregue no turno antigo.
     *
     * @param  list<array{projeto: Projeto, de: string, para: string}>  $movimentos
     * @return list<string>
     */
    private function avisos(Edicao $edicao, array $movimentos, ?Turno $origem, Turno $destino, Projeto $projeto): array
    {
        $avisos = [];
        $faixas = [];
        $reservados = [];

        foreach (Categoria::cases() as $categoria) {
            $regra = $edicao->estandes_config['regras'][$categoria->value] ?? null;

            if (($regra['ativa'] ?? false) === true) {
                $faixas[$categoria->value] = FaixaEstandes::expandir($regra['faixa'] ?? '');

                foreach ($faixas[$categoria->value] as $n) {
                    $reservados[$n] = $categoria;
                }
            }
        }

        foreach ($movimentos as $m) {
            $estande = EstandeProjeto::where('edicao_id', $edicao->id)->where('projeto_id', $m['projeto']->id)->first();

            if ($estande === null) {
                continue;
            }

            $categoria = $m['projeto']->categoria;
            $faixa = $faixas[$categoria?->value] ?? null;
            $dono = $reservados[$estande->numero] ?? null;

            if (($faixa !== null && ! in_array($estande->numero, $faixa, true)) || ($dono !== null && $dono !== $categoria)) {
                $avisos[] = sprintf(
                    '"%s" (%s) ficou no estande %s, fora da faixa da categoria.',
                    $m['projeto']->titulo,
                    $categoria?->label() ?? 'sem categoria',
                    $this->rotulo($estande->numero),
                );
            }
        }

        if ($origem !== null && $origem !== $destino) {
            $pendentes = AvaliacaoPresencial::where('projeto_id', $projeto->id)
                ->whereNull('concluida_em')
                ->where('turno', $origem->value)
                ->count();

            if ($pendentes > 0) {
                $avisos[] = sprintf(
                    '"%s" tem %d avaliação(ões) presencial(is) entregue(s) no turno antigo. Confira em Avaliação presencial → Distribuição presencial.',
                    $projeto->titulo,
                    $pendentes,
                );
            }
        }

        return $avisos;
    }

    /**
     * @param  list<array{projeto: Projeto, de: string, para: string}>  $movimentos
     * @param  list<string>  $avisos
     * @return array<string, mixed>
     */
    private function resposta(array $movimentos, array $avisos): array
    {
        return [
            'turnos' => $this->turnos->listar(),
            'remanejamento' => $this->painel(),
            'movimentos' => array_map(fn (array $m) => [
                'projeto_id' => $m['projeto']->id,
                'titulo' => $m['projeto']->titulo,
                'de' => $m['de'],
                'para' => $m['para'],
            ], $movimentos),
            'avisos' => $avisos,
        ];
    }

    /**
     * Os números dos estandes da planta em vigor (ou da prancha padrão), em
     * ordem. "Ao lado" e "fim" andam nesta lista.
     *
     * @return list<int>
     */
    public function numerosDaPlanta(?Edicao $edicao): array
    {
        $layout = $edicao === null ? null : MapaLayout::vigente($edicao);
        $dados = $layout?->dados ?? PlantaEvento::padrao();

        $numeros = array_values(array_unique(array_map(
            fn (array $e) => (int) $e['numero'],
            $dados['estandes'] ?? [],
        )));
        sort($numeros);

        return $numeros;
    }

    /** @param  Collection<int, EstandeProjeto>|null  $linhas */
    private function estandesGerados(?Edicao $edicao, ?Collection $linhas = null): bool
    {
        if ($edicao === null) {
            return false;
        }

        if ($edicao->estandes_gerados_em !== null) {
            return true;
        }

        return $linhas !== null
            ? $linhas->isNotEmpty()
            : EstandeProjeto::where('edicao_id', $edicao->id)->exists();
    }

    /** "Turno A (matutino) · Estande 042", ou só o que houver. */
    private function lugar(?Turno $turno, ?int $numero): string
    {
        if ($turno === null) {
            return 'Sem turno';
        }

        return $numero === null
            ? $turno->label()
            : $turno->label().' · Estande '.$this->rotulo($numero);
    }

    private function rotulo(int $numero): string
    {
        return str_pad((string) $numero, 3, '0', STR_PAD_LEFT);
    }

    private function edicaoOuFalha(): Edicao
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'edicao' => 'Nenhuma edição em escopo. Crie a edição da feira antes.',
            ]);
        }

        return $edicao;
    }
}
