<?php

namespace App\Services;

use App\Enums\Categoria;
use App\Enums\Turno;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Models\User;
use App\Support\FaixaEstandes;
use App\Support\RegrasTurnos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mapa do Evento → **Estandes dos Projetos**.
 *
 * Sabido o turno de cada projeto, falta o **número do estande**. A organização
 * não distribui a esmo: cada categoria ocupa um bloco do ginásio, para o
 * visitante achar a FETEC Jr inteira junta e o avaliador não atravessar o
 * pavilhão entre dois trabalhos da mesma área. Daí a regra ser **por
 * categoria**, com a faixa escrita como se escreve no papel: `1-4, 7-9, 10-52`.
 *
 * Duas listas saem de uma geração — uma por turno —, porque o estande é
 * reaproveitado: o 042 recebe um projeto de manhã e outro à tarde.
 *
 * O que acontece com o que a regra não cobre:
 *
 * - **categoria sem regra** ocupa os números que sobraram, isto é, os que
 *   nenhuma faixa reservou;
 * - **faixa pequena demais** para a categoria: o excedente também vai para os
 *   números livres, e a resposta diz quantos foram — a alternativa seria deixar
 *   projeto sem estande, que no dia do evento é pior do que um aviso.
 *
 * Depois de gerada, a troca manual é uma **troca de lugar**: mandar um projeto
 * para um número ocupado faz os dois trocarem entre si, que é o que a
 * organização faz na prática quando remaneja o corredor.
 */
class EstandesProjetosService
{
    public function __construct(private readonly RegistroAtividadeService $registros) {}

    /**
     * O que a tela precisa: as faixas salvas, o estado dos turnos (que são o
     * insumo) e as duas listas, se já houver.
     *
     * @return array<string, mixed>
     */
    public function painel(): array
    {
        $edicao = Edicao::atual();
        $turnos = $this->turnos($edicao);

        return [
            'config' => $this->config($edicao),
            'categorias' => array_map(fn (Categoria $c) => [
                'value' => $c->value,
                'label' => $c->label(),
                'total' => $turnos->filter(fn (TurnoApresentacao $t) => $t->projeto?->categoria === $c)->count(),
            ], Categoria::cases()),
            'turnos_gerados' => $turnos->isNotEmpty(),
            'capacidade' => RegrasTurnos::deArray($edicao?->turnos_config)->capacidade(),
            'gerado_em' => $edicao?->estandes_gerados_em?->toIso8601String(),
            'gerado_por' => $edicao?->estandes_gerados_por
                ? User::find($edicao->estandes_gerados_por)?->name
                : null,
            'lista' => $this->listar(),
        ];
    }

    /**
     * Guarda as faixas sem distribuir nada.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function salvarConfig(array $config): array
    {
        $edicao = $this->edicaoOuFalha();
        $limpa = $this->sanitizar($config);

        $edicao->update(['estandes_config' => $limpa]);

        return $limpa;
    }

    /**
     * Distribui os projetos pelos estandes, um turno de cada vez.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function gerar(array $config, User $admin): array
    {
        $edicao = $this->edicaoOuFalha();
        $limpa = $this->sanitizar($config);
        $alocacoesTurno = $this->turnos($edicao);

        if ($alocacoesTurno->isEmpty()) {
            throw ValidationException::withMessages([
                'turnos' => 'Gere primeiro a lista de turnos: é dela que sai quem apresenta em cada horário.',
            ]);
        }

        $capacidade = RegrasTurnos::deArray($edicao->turnos_config)->capacidade();
        $linhas = [];
        $avisos = [];

        foreach (Turno::cases() as $turno) {
            $doTurno = $alocacoesTurno->filter(fn (TurnoApresentacao $t) => $t->turno === $turno);

            if ($doTurno->isEmpty()) {
                continue;
            }

            $resultado = $this->distribuirTurno($doTurno, $limpa, $capacidade[$turno->value]);

            foreach ($resultado['alocacoes'] as $alocacao) {
                $linhas[] = [
                    'edicao_id' => $edicao->id,
                    'projeto_id' => $alocacao['projeto_id'],
                    'turno' => $turno->value,
                    'numero' => $alocacao['numero'],
                    'regra' => $alocacao['regra'],
                    'manual' => false,
                ];
            }

            foreach ($resultado['avisos'] as $aviso) {
                $avisos[] = ['turno' => $turno->label()] + $aviso;
            }
        }

        DB::transaction(function () use ($edicao, $limpa, $linhas, $admin) {
            // Só a última distribuição vale — a anterior sai inteira.
            EstandeProjeto::where('edicao_id', $edicao->id)->delete();

            foreach ($linhas as $linha) {
                EstandeProjeto::create($linha);
            }

            $edicao->update([
                'estandes_config' => $limpa,
                'estandes_gerados_em' => now(),
                'estandes_gerados_por' => $admin->id,
            ]);

            $this->registros->estandesGerados($admin, [
                'total' => count($linhas),
                'faixas' => $this->descreverFaixas($limpa),
            ]);
        });

        return ['lista' => $this->listar(), 'avisos' => $avisos];
    }

    /**
     * As duas listas, por turno, em ordem de número de estande.
     *
     * @return array<string, mixed>
     */
    public function listar(): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return $this->listaVazia();
        }

        $alocacoes = EstandeProjeto::where('edicao_id', $edicao->id)
            ->with(['projeto.area:id,nome', 'projeto.instituicao', 'projeto.user:id,name'])
            ->orderBy('numero')
            ->get()
            ->filter(fn (EstandeProjeto $e) => $e->projeto !== null);

        if ($alocacoes->isEmpty()) {
            return $this->listaVazia();
        }

        $porTurno = [];

        foreach (Turno::cases() as $turno) {
            $doTurno = $alocacoes->filter(fn (EstandeProjeto $e) => $e->turno === $turno);

            $porTurno[$turno->value] = [
                'turno' => $turno->value,
                'label' => $turno->label(),
                'total' => $doTurno->count(),
                'estandes' => $doTurno->map(fn (EstandeProjeto $e) => $this->linha($e))->values()->all(),
            ];
        }

        return ['gerada' => true, 'total' => $alocacoes->count(), 'turnos' => $porTurno];
    }

    /**
     * Move um projeto para outro número — trocando de lugar com quem estiver
     * lá, se houver alguém.
     *
     * O destino é sempre dentro do **mesmo turno**: mudar de turno é decisão da
     * outra tela, e fazê-la por aqui embaralharia as duas listas.
     *
     * @return array<string, mixed>
     */
    public function mover(int $projetoId, int $numero, User $admin): array
    {
        $edicao = $this->edicaoOuFalha();

        $alocacao = EstandeProjeto::where('edicao_id', $edicao->id)
            ->where('projeto_id', $projetoId)
            ->first();

        if ($alocacao === null) {
            throw ValidationException::withMessages([
                'projeto_id' => 'Este projeto não está na distribuição de estandes em vigor.',
            ]);
        }

        if ($numero < 1) {
            throw ValidationException::withMessages(['numero' => 'O número do estande começa em 1.']);
        }

        if ($alocacao->numero === $numero) {
            return $this->listar();
        }

        $ocupante = EstandeProjeto::where('edicao_id', $edicao->id)
            ->where('turno', $alocacao->turno->value)
            ->where('numero', $numero)
            ->first();

        $origem = $alocacao->numero;

        DB::transaction(function () use ($alocacao, $ocupante, $numero, $origem, $admin) {
            if ($ocupante !== null) {
                // Troca de lugar: o número de destino é liberado antes, senão a
                // chave única (edição, turno, número) barra o caminho.
                $ocupante->update(['numero' => 0, 'manual' => true]);
            }

            $alocacao->update(['numero' => $numero, 'manual' => true]);

            $this->registros->estandeProjetoMovido(
                $alocacao->projeto,
                $admin,
                $this->rotulo($origem),
                $this->rotulo($numero),
            );

            if ($ocupante !== null) {
                $ocupante->update(['numero' => $origem]);

                // A troca tem dois lados, e a trilha precisa contar os dois: sem
                // esta linha, o projeto que saiu do 042 teria mudado sozinho.
                $this->registros->estandeProjetoMovido(
                    $ocupante->projeto,
                    $admin,
                    $this->rotulo($numero),
                    $this->rotulo($origem),
                );
            }
        });

        return $this->listar();
    }

    /**
     * Os estandes com o que há neles nos dois turnos — a leitura da planta do
     * evento (Sprint 120).
     *
     * @return array<int, array<string, mixed>>
     */
    public function porEstande(): array
    {
        $lista = $this->listar();
        $mapa = [];

        foreach ($lista['turnos'] as $turno) {
            foreach ($turno['estandes'] as $estande) {
                $numero = $estande['numero'];
                $mapa[$numero] ??= ['numero' => $numero, 'A' => null, 'B' => null];
                $mapa[$numero][$turno['turno']] = $estande;
            }
        }

        ksort($mapa);

        return $mapa;
    }

    // --- Exportação ------------------------------------------------------

    public function exportarTxt(): string
    {
        $lista = $this->listar();
        $linhas = [];

        foreach ($lista['turnos'] as $turno) {
            $linhas[] = mb_strtoupper($turno['label']).' — '.$turno['total'].' estande(s)';
            $linhas[] = str_repeat('-', 60);

            foreach ($turno['estandes'] as $e) {
                $linhas[] = sprintf('%s - %s', $e['estande'], $e['titulo']);
                $linhas[] = '      '.$e['categoria'].' / '.($e['area'] ?? 'sem área');
                $linhas[] = '      '.$e['escola'];
            }

            $linhas[] = '';
        }

        return implode("\n", $linhas);
    }

    public function exportarCsv(): string
    {
        $lista = $this->listar();
        $saida = "\u{FEFF}Turno;Estande;Projeto;Categoria;Área;Escola;Cidade;UF;Orientador\n";

        foreach ($lista['turnos'] as $turno) {
            foreach ($turno['estandes'] as $e) {
                $saida .= implode(';', array_map(
                    fn ($v) => '"'.str_replace('"', '""', (string) $v).'"',
                    [
                        $turno['label'], $e['estande'], $e['titulo'], $e['categoria'], $e['area'],
                        $e['escola'], $e['cidade'], $e['uf'], $e['orientador'],
                    ],
                ))."\n";
            }
        }

        return $saida;
    }

    public function exportarPdf(): string
    {
        return app(PdfService::class)->render('pdf.estandes', [
            'lista' => $this->listar(),
            'edicao' => Edicao::atual()?->nome ?? 'FETECMS',
            'gerado_em' => now()->format('d/m/Y H:i'),
        ]);
    }

    // --- O algoritmo -----------------------------------------------------

    /**
     * Distribui um turno: primeiro as categorias com faixa, na ordem da lista
     * final; depois o resto, nos números livres.
     *
     * @param  Collection<int, TurnoApresentacao>  $doTurno
     * @param  array<string, mixed>  $config
     * @return array{alocacoes: list<array{projeto_id:int, numero:int, regra:?string}>, avisos: list<array<string,mixed>>}
     */
    private function distribuirTurno(Collection $doTurno, array $config, int $capacidade): array
    {
        $projetos = $doTurno->map(fn (TurnoApresentacao $t) => $t->projeto)->values();
        $alocacoes = [];
        $avisos = [];
        $usados = [];

        // Os números que as faixas reservam — eles não entram no bolo livre nem
        // quando a categoria dona deles não preenche tudo.
        $reservados = [];

        foreach (Categoria::cases() as $categoria) {
            $regra = $config['regras'][$categoria->value] ?? null;

            if (($regra['ativa'] ?? false) === true) {
                foreach (FaixaEstandes::expandir($regra['faixa'] ?? '') as $n) {
                    $reservados[$n] = $categoria->value;
                }
            }
        }

        foreach (Categoria::cases() as $categoria) {
            $regra = $config['regras'][$categoria->value] ?? null;

            if (($regra['ativa'] ?? false) !== true) {
                continue;
            }

            $daCategoria = $projetos->filter(fn (Projeto $p) => $p->categoria === $categoria)->values();
            $numeros = FaixaEstandes::expandir($regra['faixa'] ?? '');
            $cabem = min($daCategoria->count(), count($numeros));

            for ($i = 0; $i < $cabem; $i++) {
                $alocacoes[] = [
                    'projeto_id' => $daCategoria[$i]->id,
                    'numero' => $numeros[$i],
                    'regra' => $categoria->value,
                ];
                $usados[$daCategoria[$i]->id] = true;
            }

            if ($daCategoria->count() > $cabem) {
                $avisos[] = [
                    'categoria' => $categoria->label(),
                    'sobraram' => $daCategoria->count() - $cabem,
                    'motivo' => sprintf(
                        'A faixa de %s tem %d estande(s) para %d projeto(s); o excedente foi para os números livres.',
                        $categoria->label(),
                        count($numeros),
                        $daCategoria->count(),
                    ),
                ];
            }
        }

        // O bolo livre: todo número até a capacidade que nenhuma faixa reservou.
        $livres = [];

        for ($n = 1; $n <= $capacidade; $n++) {
            if (! isset($reservados[$n])) {
                $livres[] = $n;
            }
        }

        foreach ($projetos as $projeto) {
            if (isset($usados[$projeto->id])) {
                continue;
            }

            if ($livres === []) {
                // Sem número livre nenhum: o projeto fica fora da distribuição e
                // a tela avisa — melhor do que inventar um estande que não existe.
                $avisos[] = [
                    'categoria' => $projeto->categoria?->label() ?? '—',
                    'sobraram' => 1,
                    'motivo' => sprintf('"%s" ficou sem estande: não há número livre neste turno.', $projeto->titulo),
                ];

                continue;
            }

            $alocacoes[] = [
                'projeto_id' => $projeto->id,
                'numero' => array_shift($livres),
                'regra' => null,
            ];
        }

        usort($alocacoes, fn (array $a, array $b) => $a['numero'] <=> $b['numero']);

        return ['alocacoes' => $alocacoes, 'avisos' => $avisos];
    }

    // --- Apoio -----------------------------------------------------------

    /**
     * A configuração normalizada: uma regra por categoria, com o texto da faixa
     * como o admin escreveu.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function sanitizar(array $config): array
    {
        $regras = [];
        $faixas = [];

        foreach (Categoria::cases() as $categoria) {
            $bruta = $config['regras'][$categoria->value] ?? [];
            $ativa = (bool) ($bruta['ativa'] ?? false);
            $faixa = trim((string) ($bruta['faixa'] ?? ''));

            if ($ativa && FaixaEstandes::expandir($faixa) === []) {
                throw ValidationException::withMessages([
                    'regras' => sprintf(
                        'Informe os estandes de %s — por exemplo "1-40, 61-70".',
                        $categoria->label(),
                    ),
                ]);
            }

            $regras[$categoria->value] = ['ativa' => $ativa, 'faixa' => $faixa];

            if ($ativa) {
                $faixas[$categoria->value] = $faixa;
            }
        }

        // Um estande em duas categorias é dois projetos no mesmo lugar físico:
        // recusar é mais honesto do que escolher uma das duas por conta própria.
        $conflitos = FaixaEstandes::sobrepostos($faixas);

        if ($conflitos !== []) {
            throw ValidationException::withMessages([
                'regras' => 'Estes estandes estão em mais de uma categoria: '
                    .FaixaEstandes::comprimir($conflitos).'.',
            ]);
        }

        return ['regras' => $regras];
    }

    /** @return array<string, mixed> */
    private function config(?Edicao $edicao): array
    {
        $guardada = $edicao?->estandes_config['regras'] ?? [];
        $regras = [];

        foreach (Categoria::cases() as $categoria) {
            $regras[$categoria->value] = [
                'ativa' => (bool) ($guardada[$categoria->value]['ativa'] ?? false),
                'faixa' => (string) ($guardada[$categoria->value]['faixa'] ?? ''),
            ];
        }

        return ['regras' => $regras];
    }

    /**
     * As alocações de turno da edição — o insumo desta tela.
     *
     * @return Collection<int, TurnoApresentacao>
     */
    private function turnos(?Edicao $edicao): Collection
    {
        if ($edicao === null) {
            return collect();
        }

        return TurnoApresentacao::where('edicao_id', $edicao->id)
            ->with(['projeto.area:id,nome', 'projeto.instituicao'])
            ->get()
            ->filter(fn (TurnoApresentacao $t) => $t->projeto !== null)
            ->values();
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

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function descreverFaixas(array $config): array
    {
        $descricao = [];

        foreach (Categoria::cases() as $categoria) {
            $regra = $config['regras'][$categoria->value] ?? null;

            if (($regra['ativa'] ?? false) === true) {
                $descricao[] = $categoria->label().': '.$regra['faixa'];
            }
        }

        return $descricao;
    }

    /** @return array<string, mixed> */
    private function linha(EstandeProjeto $e): array
    {
        $projeto = $e->projeto;
        $cidade = $projeto->instituicao?->cidade ?? $projeto->cidade;

        return [
            'projeto_id' => $projeto->id,
            'numero' => $e->numero,
            'estande' => $this->rotulo($e->numero),
            'titulo' => $projeto->titulo,
            'categoria' => $projeto->categoria?->label(),
            'categoria_value' => $projeto->categoria?->value,
            'area' => $projeto->area?->nome,
            'escola' => $projeto->instituicao?->nome ?? '—',
            'cidade' => $cidade?->nome,
            'uf' => $cidade?->estado?->uf ?? $projeto->estado?->uf,
            'orientador' => $projeto->user?->name,
            'turno' => $e->turno->value,
            'manual' => (bool) $e->manual,
        ];
    }

    /** O número como a feira o escreve: três dígitos, 001…230. */
    private function rotulo(int $numero): string
    {
        return str_pad((string) $numero, 3, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function listaVazia(): array
    {
        return [
            'gerada' => false,
            'total' => 0,
            'turnos' => collect(Turno::cases())
                ->mapWithKeys(fn (Turno $t) => [$t->value => [
                    'turno' => $t->value,
                    'label' => $t->label(),
                    'total' => 0,
                    'estandes' => [],
                ]])
                ->all(),
        ];
    }
}
