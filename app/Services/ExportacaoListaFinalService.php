<?php

namespace App\Services;

use App\Models\Aluno;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\TurnoApresentacao;
use App\Support\ClassesEscolares;
use App\Support\CodigoParticipante;
use App\Support\PlanilhaXlsx;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lista final → **Exportar** (Sprint 160): a lista oficial em planilha, com as
 * colunas que cada uso pede.
 *
 * A mesma lista serve a meia dúzia de trabalhos diferentes no evento — a lista
 * nominal do credenciamento, os crachás, os certificados, o painel de nomes, a
 * planilha de contatos —, e cada um quer outras colunas e outro **nível**: uma
 * linha **por pessoa** (estudante, orientador, coorientador) ou **por
 * projeto**. Em vez de um relatório fixo por pedido, a tela monta o recorte:
 * escolhe-se o nível, marcam-se as colunas e o formato (CSV ou Excel). Os
 * **modelos prontos** são só colunas pré-marcadas — a "lista nominal" é o
 * primeiro, porque é o pedido urgente.
 *
 * O registro de colunas mora aqui, num lugar só: acrescentar uma coluna é
 * escrever o rótulo e de onde ela sai, e ela aparece na tela sozinha.
 */
class ExportacaoListaFinalService
{
    public const NIVEL_PESSOA = 'pessoa';

    public const NIVEL_PROJETO = 'projeto';

    /** Rótulo de cada coluna, por nível. A ordem é a da tela. */
    private const COLUNAS = [
        self::NIVEL_PESSOA => [
            'nome' => 'Nome completo',
            'funcao' => 'Função',
            'cpf' => 'CPF',
            'email' => 'E-mail',
            'telefone' => 'Telefone',
            'serie' => 'Série (estudante)',
            'camiseta' => 'Camiseta',
            'codigo_cracha' => 'Código do crachá',
            'codigo_projeto' => 'Código do projeto',
            'projeto' => 'Projeto',
            'instituicao' => 'Instituição',
            'cidade' => 'Cidade/UF',
            'categoria' => 'Categoria',
            'area' => 'Área',
            'subarea' => 'Subárea',
            'estande' => 'Estande',
            'turno' => 'Turno',
            'origem' => 'Origem',
            'credenciado' => 'Projeto credenciado',
        ],
        self::NIVEL_PROJETO => [
            'codigo_projeto' => 'Código do projeto',
            'projeto' => 'Título',
            'categoria' => 'Categoria',
            'area' => 'Área',
            'subarea' => 'Subárea',
            'instituicao' => 'Instituição',
            'cidade' => 'Cidade/UF',
            'orientador' => 'Orientador(a)',
            'orientador_email' => 'E-mail do orientador',
            'orientador_telefone' => 'Telefone do orientador',
            'coorientador' => 'Coorientador(a)',
            'estudantes' => 'Estudantes',
            'qtd_estudantes' => 'Qtd. de estudantes',
            'qtd_pessoas' => 'Qtd. de pessoas',
            'estande' => 'Estande',
            'turno' => 'Turno',
            'origem' => 'Origem',
            'credenciado' => 'Credenciado',
            'media' => 'Média da avaliação online',
        ],
    ];

    /**
     * Recortes prontos: só um nome para um conjunto de colunas marcadas.
     *
     * @var array<string, array{rotulo: string, descricao: string, nivel: string, colunas: list<string>}>
     */
    private const MODELOS = [
        'nominal' => [
            'rotulo' => 'Lista nominal',
            'descricao' => 'Estudantes, orientadores e coorientadores: nome, função, projeto, código, instituição, categoria e área. Para credenciamento, crachás, certificados e painel de nomes.',
            'nivel' => self::NIVEL_PESSOA,
            'colunas' => ['nome', 'funcao', 'projeto', 'codigo_projeto', 'instituicao', 'categoria', 'area'],
        ],
        'crachas' => [
            'rotulo' => 'Crachás',
            'descricao' => 'Nome, função, código do crachá (o do QR) e do projeto, instituição e turno.',
            'nivel' => self::NIVEL_PESSOA,
            'colunas' => ['nome', 'funcao', 'codigo_cracha', 'codigo_projeto', 'projeto', 'instituicao', 'turno'],
        ],
        'certificados' => [
            'rotulo' => 'Certificados',
            'descricao' => 'Nome completo, CPF, função e projeto.',
            'nivel' => self::NIVEL_PESSOA,
            'colunas' => ['nome', 'cpf', 'funcao', 'projeto', 'codigo_projeto', 'categoria'],
        ],
        'contatos' => [
            'rotulo' => 'Contatos',
            'descricao' => 'Nome, função, e-mail e telefone de cada pessoa, com o projeto.',
            'nivel' => self::NIVEL_PESSOA,
            'colunas' => ['nome', 'funcao', 'email', 'telefone', 'projeto', 'codigo_projeto'],
        ],
        'projetos' => [
            'rotulo' => 'Projetos',
            'descricao' => 'Uma linha por projeto: código, título, categoria, área, instituição, equipe, estande e turno.',
            'nivel' => self::NIVEL_PROJETO,
            'colunas' => ['codigo_projeto', 'projeto', 'categoria', 'area', 'instituicao', 'cidade', 'orientador', 'coorientador', 'estudantes', 'estande', 'turno'],
        ],
    ];

    public function __construct(private readonly ListaFinalService $listas) {}

    /** @return array<string, mixed> */
    public function opcoes(): array
    {
        $colunas = [];
        foreach (self::COLUNAS as $nivel => $lista) {
            foreach ($lista as $chave => $rotulo) {
                $colunas[$nivel][] = ['chave' => $chave, 'rotulo' => $rotulo];
            }
        }

        return [
            'niveis' => [
                ['valor' => self::NIVEL_PESSOA, 'rotulo' => 'Uma linha por pessoa'],
                ['valor' => self::NIVEL_PROJETO, 'rotulo' => 'Uma linha por projeto'],
            ],
            'colunas' => $colunas,
            'modelos' => collect(self::MODELOS)->map(fn ($m, $k) => ['chave' => $k] + $m)->values()->all(),
            'formatos' => ['csv', 'xlsx'],
        ];
    }

    /**
     * O arquivo: bytes, nome e tipo.
     *
     * @param  list<string>  $colunas
     * @return array{conteudo: string, nome: string, tipo: string}
     */
    public function exportar(ListaFinal $lista, string $nivel, array $colunas, string $formato, ?string $modelo = null): array
    {
        if (! isset(self::COLUNAS[$nivel])) {
            throw ValidationException::withMessages(['nivel' => 'Nível de exportação desconhecido.']);
        }

        // Só as colunas que existem neste nível, na ordem em que foram pedidas
        // (a lista nominal tem uma ordem certa: nome, função, projeto…).
        $colunas = array_values(array_unique(array_filter($colunas, fn ($c) => isset(self::COLUNAS[$nivel][$c]))));

        if ($colunas === []) {
            throw ValidationException::withMessages(['colunas' => 'Marque ao menos uma coluna.']);
        }

        $linhas = $nivel === self::NIVEL_PESSOA ? $this->linhasPorPessoa($lista) : $this->linhasPorProjeto($lista);
        $cabecalho = array_map(fn (string $c) => self::COLUNAS[$nivel][$c], $colunas);
        $matriz = $linhas->map(fn (array $l) => array_map(fn (string $c) => $l[$c] ?? null, $colunas))->all();

        $base = sprintf('lista-final-%s-v%d', $modelo ?? ($nivel === self::NIVEL_PESSOA ? 'pessoas' : 'projetos'), (int) $lista->versao);

        if ($formato === 'xlsx') {
            return [
                'conteudo' => PlanilhaXlsx::gerar('Lista final', $cabecalho, $matriz),
                'nome' => $base.'.xlsx',
                'tipo' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ];
        }

        return ['conteudo' => $this->csv($cabecalho, $matriz), 'nome' => $base.'.csv', 'tipo' => 'text/csv; charset=UTF-8'];
    }

    /**
     * Uma linha por pessoa, na ordem da lista (código do projeto) e, dentro do
     * projeto, orientador → coorientador → estudantes em ordem alfabética.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function linhasPorPessoa(ListaFinal $lista): Collection
    {
        $linhas = collect();
        $projetos = $this->projetos($lista);
        $acompanhantes = app(SuporteProjetoService::class)
            ->acompanhantesAprovados(array_map(fn ($par) => $par[0]->id, $projetos))
            ->groupBy('projeto_id');

        foreach ($projetos as [$projeto, $comum]) {
            $orientador = $projeto->user;

            if ($orientador !== null) {
                $linhas->push($comum + [
                    'nome' => $orientador->name,
                    'funcao' => 'Orientador(a)',
                    'cpf' => $this->cpf($orientador->orientadorProfile?->cpf),
                    'email' => $orientador->email,
                    'telefone' => $orientador->orientadorProfile?->telefone,
                    'camiseta' => $orientador->orientadorProfile?->camiseta,
                    'codigo_cracha' => $this->cracha($comum, $orientador->orientadorProfile?->cpf, CodigoParticipante::PAPEL_ORIENTADOR, $orientador->id),
                ]);
            }

            if ($projeto->coorientador !== null) {
                $co = $projeto->coorientador;
                $linhas->push($comum + [
                    'nome' => $co->nome,
                    'funcao' => 'Coorientador(a)',
                    'cpf' => $this->cpf($co->cpf),
                    'email' => $co->email,
                    'telefone' => $co->telefone,
                    'camiseta' => $co->camiseta,
                    'codigo_cracha' => $this->cracha($comum, $co->cpf, CodigoParticipante::PAPEL_COORIENTADOR, $co->id),
                ]);
            }

            foreach ($projeto->alunos->sortBy(fn (Aluno $a) => mb_strtolower($a->nome)) as $aluno) {
                $linhas->push($comum + [
                    'nome' => $aluno->nome,
                    'funcao' => 'Estudante',
                    'cpf' => $this->cpf($aluno->cpf),
                    'email' => $aluno->email,
                    'telefone' => $aluno->telefone,
                    'serie' => ClassesEscolares::serieLabel($aluno->modalidade, $aluno->ano_escolar),
                    'camiseta' => $aluno->camiseta,
                    'codigo_cracha' => $this->cracha($comum, $aluno->cpf, CodigoParticipante::PAPEL_ALUNO, $aluno->id),
                ]);
            }

            // Acompanhante aprovado (Sprint 162): entra no evento, tem crachá.
            foreach ($acompanhantes->get($projeto->id, collect()) as $s) {
                $linhas->push($comum + [
                    'nome' => $s->acompanhante_nome,
                    'funcao' => 'Acompanhante'.($s->aluno?->nome ? ' de '.$s->aluno->nome : ''),
                    'cpf' => $this->cpf($s->acompanhante_documento),
                    'codigo_cracha' => $this->cracha($comum, $s->acompanhante_documento, CodigoParticipante::PAPEL_ACOMPANHANTE, $s->id),
                ]);
            }
        }

        return $linhas->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function linhasPorProjeto(ListaFinal $lista): Collection
    {
        return collect($this->projetos($lista))->map(function (array $par) {
            [$projeto, $comum] = $par;
            $estudantes = $projeto->alunos->sortBy(fn (Aluno $a) => mb_strtolower($a->nome))->pluck('nome');

            return $comum + [
                'orientador' => $projeto->user?->name,
                'orientador_email' => $projeto->user?->email,
                'orientador_telefone' => $projeto->user?->orientadorProfile?->telefone,
                'coorientador' => $projeto->coorientador?->nome,
                'estudantes' => $estudantes->implode('; '),
                'qtd_estudantes' => $estudantes->count(),
                'qtd_pessoas' => $estudantes->count() + ($projeto->user ? 1 : 0) + ($projeto->coorientador ? 1 : 0),
                'media' => $comum['media'],
            ];
        })->values();
    }

    /**
     * Os projetos da lista na ordem dela, cada um com os campos que toda linha
     * repete (código, título, categoria…).
     *
     * @return list<array{0: Projeto, 1: array<string, mixed>}>
     */
    private function projetos(ListaFinal $lista): array
    {
        $itens = $this->listas->itensDaLista($lista);

        if ($itens === []) {
            return [];
        }

        $ids = array_column($itens, 'projeto_id');
        $projetos = Projeto::query()
            ->whereIn('id', $ids)
            ->with([
                'user.orientadorProfile', 'coorientador', 'alunos', 'area:id,nome', 'subarea:id,nome',
                'instituicao.cidade.estado', 'credenciamento',
            ])
            ->get()
            ->keyBy('id');

        $estandes = EstandeProjeto::whereIn('projeto_id', $ids)->get()->keyBy('projeto_id');
        $turnos = TurnoApresentacao::whereIn('projeto_id', $ids)->get()->keyBy('projeto_id');
        $ano = (int) ($lista->edicao?->ano ?? now()->year);

        $resultado = [];

        foreach ($itens as $item) {
            $projeto = $projetos->get($item['projeto_id']);

            if ($projeto === null) {
                continue;
            }

            $estande = $estandes->get($projeto->id);
            $turno = $estande?->turno ?? $turnos->get($projeto->id)?->turno;
            $cidade = $projeto->instituicao?->cidade;

            $resultado[] = [$projeto, [
                '_ano' => $ano,
                '_projeto_id' => $projeto->id,
                'codigo_projeto' => $item['codigo'],
                'projeto' => $projeto->titulo,
                'instituicao' => $projeto->instituicao?->nome,
                'cidade' => $cidade ? trim($cidade->nome.($cidade->estado?->uf ? ' - '.$cidade->estado->uf : '')) : null,
                'categoria' => $projeto->categoria?->label(),
                'area' => $projeto->area?->nome,
                'subarea' => $projeto->subarea?->nome,
                'estande' => $estande?->numero,
                'turno' => $turno?->curto(),
                'origem' => $projeto->feira_afiliada
                    ? 'Credencial'.($projeto->feira_afiliada_nome ? ' · '.$projeto->feira_afiliada_nome : '')
                    : 'Finalista',
                'credenciado' => $projeto->credenciamento?->finalizado_em ? 'Sim' : 'Não',
                'media' => $item['media'],
            ]];
        }

        return $resultado;
    }

    /** @param  array<string, mixed>  $comum */
    private function cracha(array $comum, ?string $cpf, string $papel, int $id): string
    {
        return CodigoParticipante::montar($comum['_ano'], $comum['_projeto_id'], $cpf, $papel, $id);
    }

    private function cpf(?string $cpf): ?string
    {
        $d = preg_replace('/\D/', '', (string) $cpf);

        return strlen((string) $d) === 11
            ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d)
            : null;
    }

    /**
     * CSV para o Excel em português: BOM (acentos), separador `;`.
     *
     * @param  list<string>  $cabecalho
     * @param  list<list<mixed>>  $linhas
     */
    private function csv(array $cabecalho, array $linhas): string
    {
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}");
        fputcsv($saida, $cabecalho, ';', '"', '');

        foreach ($linhas as $linha) {
            fputcsv($saida, array_map(fn ($v) => $v === null ? '' : (string) $v, $linha), ';', '"', '');
        }

        rewind($saida);
        $csv = (string) stream_get_contents($saida);
        fclose($saida);

        return $csv;
    }
}
