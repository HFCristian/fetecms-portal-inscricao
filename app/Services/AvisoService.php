<?php

namespace App\Services;

use App\Enums\PublicoMala;
use App\Models\Aviso;
use App\Models\AvisoVisualizacao;
use App\Models\Edicao;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Avisos na tela: o admin escreve título e mensagem e o card aparece para os
 * orientadores conectados. Só um aviso fica ativo por vez — publicar um novo
 * encerra o anterior.
 *
 * O texto guarda as variáveis como o admin escreveu; elas são resolvidas na
 * hora da leitura, para que "faltam X minutos" seja o tempo real de quem está
 * lendo, e não o de quando o aviso foi escrito.
 */
class AvisoService
{
    /** Variáveis aceitas no título e na mensagem (a tela monta os botões a partir daqui). */
    public const VARIAVEIS = [
        [
            'chave' => 'prazo_inscricoes',
            'rotulo' => 'Prazo das inscrições',
            'descricao' => 'A data-limite de submissão, ex.: "30/09/2026 às 23:59".',
        ],
        [
            'chave' => 'tempo_restante',
            'rotulo' => 'Tempo restante',
            'descricao' => 'Quanto falta para o prazo, contado na hora em que a pessoa lê: "45 minutos".',
        ],
        [
            'chave' => 'inicio_avaliacoes',
            'rotulo' => 'Início das avaliações',
            'descricao' => 'A data de liberação da avaliação online, ex.: "05/10/2026 às 08:00".',
        ],
    ];

    /** Texto que o formulário do admin já abre preenchido. */
    public const MODELO_TITULO = 'As inscrições estão se encerrando';

    public const MODELO_MENSAGEM = 'As inscrições da XVI FETECMS se encerram em {{prazo_inscricoes}} — faltam {{tempo_restante}}. '
        ."Depois desse horário não será possível submeter, editar ou cancelar projetos.\n\n"
        .'Revise seu checklist e confirme a submissão antes do prazo. As avaliações começam em {{inicio_avaliacoes}}.';

    private const SEM_DATA = 'uma data ainda a definir';

    public function __construct(
        private readonly InscricoesService $inscricoes,
        private readonly PublicoUsuariosService $publicos,
    ) {}

    /** Público usado quando o admin não escolhe nenhum (o de sempre). */
    public const PUBLICO_PADRAO = [PublicoMala::Orientadores];

    /** O aviso mais recente no ar, se houver. */
    public function ativo(): ?Aviso
    {
        return Aviso::vigente()->latest('id')->first();
    }

    /**
     * Todos os avisos no ar, do mais novo para o mais antigo. Podem ser vários:
     * um por público.
     *
     * @return Collection<int, Aviso>
     */
    public function vigentes()
    {
        return Aviso::vigente()->latest('id')->get();
    }

    /**
     * Publica um aviso novo. Republicar para a MESMA seleção de públicos encerra
     * o anterior daquele recorte — quem lê não recebe dois cards do mesmo
     * assunto. Avisos de públicos diferentes convivem.
     *
     * @param  array<int, PublicoMala>  $publicos
     */
    public function publicar(
        User $admin,
        string $titulo,
        string $mensagem,
        array $publicos = [],
        ?CarbonInterface $expiraEm = null,
    ): Aviso {
        $alvo = $publicos !== [] ? $publicos : self::PUBLICO_PADRAO;
        $valores = array_map(fn (PublicoMala $p) => $p->value, $alvo);

        return DB::transaction(function () use ($admin, $titulo, $mensagem, $valores, $expiraEm) {
            $this->encerrarMesmoPublico($valores);

            return Aviso::create([
                'titulo' => $titulo,
                'mensagem' => $mensagem,
                'user_id' => $admin->id,
                'autor_nome' => $admin->name,
                'publicos' => $valores,
                'expira_em' => $expiraEm,
            ]);
        });
    }

    /** Encerra os avisos no ar que miram exatamente a mesma seleção de públicos. */
    private function encerrarMesmoPublico(array $valores): void
    {
        $mesmos = collect($valores)->sort()->values()->all();

        Aviso::vigente()->get()->each(function (Aviso $aviso) use ($mesmos) {
            $atual = collect($aviso->publicos ?? [])->sort()->values()->all();

            if ($atual === $mesmos) {
                $aviso->update(['encerrado_em' => now()]);
            }
        });
    }

    /** Tira o aviso do ar. Idempotente: encerrar duas vezes não muda a primeira data. */
    public function encerrar(Aviso $aviso): Aviso
    {
        if ($aviso->ativo()) {
            $aviso->update(['encerrado_em' => now()]);
        }

        return $aviso->fresh();
    }

    /**
     * O aviso que ESTE usuário deve ver agora: entre os que estão no ar, o mais
     * recente cujo público o alcança e que ele ainda não fechou. Um card por
     * vez, mesmo com vários avisos publicados.
     */
    public function paraUsuario(?User $user): ?Aviso
    {
        if (! $user || ! $user->is_active) {
            return null;
        }

        $fechados = AvisoVisualizacao::where('user_id', $user->id)
            ->whereNotNull('fechado_em')
            ->pluck('aviso_id')
            ->all();

        foreach ($this->vigentes() as $aviso) {
            if (in_array($aviso->id, $fechados, true)) {
                continue;
            }

            if ($this->publicos->alcanca($user, $aviso->publicosAlvo() ?: self::PUBLICO_PADRAO)) {
                return $aviso;
            }
        }

        return null;
    }

    /** Marca que o card chegou à tela desta pessoa (a primeira vez é a que vale). */
    public function registrarVisto(Aviso $aviso, User $user): void
    {
        AvisoVisualizacao::firstOrCreate(
            ['aviso_id' => $aviso->id, 'user_id' => $user->id],
            ['visto_em' => now()],
        );
    }

    /** Marca que a pessoa fechou o card — ele não volta mais para ela. */
    public function registrarFechado(Aviso $aviso, User $user): void
    {
        $visualizacao = AvisoVisualizacao::firstOrCreate(
            ['aviso_id' => $aviso->id, 'user_id' => $user->id],
            ['visto_em' => now()],
        );

        if ($visualizacao->fechado_em === null) {
            $visualizacao->update(['fechado_em' => now()]);
        }
    }

    /**
     * Troca as variáveis pelos valores de agora. Aceita `{{chave}}` com ou sem
     * espaços dentro das chaves, como na mala direta.
     */
    public function personalizar(string $texto): string
    {
        $valores = [
            'prazo_inscricoes' => $this->comoData($this->inscricoes->prazo()),
            'tempo_restante' => $this->tempoRestante(),
            'inicio_avaliacoes' => $this->comoData(Edicao::atual()?->avaliacao_liberada_em),
        ];

        foreach (self::VARIAVEIS as $variavel) {
            $chave = $variavel['chave'];
            $texto = preg_replace('/\{\{\s*'.$chave.'\s*\}\}/u', $valores[$chave], $texto);
        }

        return $texto;
    }

    /** O aviso já com as variáveis resolvidas, pronto para a tela. */
    public function paraTela(Aviso $aviso): array
    {
        return [
            'id' => $aviso->id,
            'titulo' => $this->personalizar($aviso->titulo),
            'mensagem' => $this->personalizar($aviso->mensagem),
            'publicado_em' => $aviso->created_at?->format('d/m/Y H:i'),
            'expira_em_label' => $aviso->expira_em?->format('d/m/Y H:i'),
        ];
    }

    /**
     * Quantas pessoas estes públicos alcançam hoje — usado na prévia, antes de
     * o aviso existir.
     *
     * @param  array<int, PublicoMala>  $publicos
     */
    public function alcancados(array $publicos): int
    {
        return $this->publicos->queryUniao($publicos !== [] ? $publicos : self::PUBLICO_PADRAO)->count();
    }

    /** Quantas pessoas o público deste aviso alcança hoje — a base do relatório. */
    public function totalDeDestinatarios(Aviso $aviso): int
    {
        return $this->publicos->queryUniao($aviso->publicosAlvo() ?: self::PUBLICO_PADRAO)->count();
    }

    /** Cabeçalho do CSV do relatório, na ordem em que as colunas aparecem. */
    private const CABECALHO_CSV = ['Nome', 'E-mail', 'Situação', 'Viu em', 'Fechou em'];

    /** Rótulos das situações, também usados como filtro na tela. */
    public const SITUACOES = [
        'fechado' => 'Fechou o aviso',
        'visto' => 'Viu e deixou aberto',
        'nao_visto' => 'Ainda não viu',
    ];

    /**
     * Avisos publicados, do mais recente para o mais antigo, com as contagens
     * de quem viu e de quem fechou.
     */
    public function historico(int $porPagina = 10): LengthAwarePaginator
    {
        $avisos = Aviso::query()
            ->withCount([
                'visualizacoes as vistos',
                'visualizacoes as fechados' => fn ($q) => $q->whereNotNull('fechado_em'),
            ])
            ->latest('id')
            ->paginate($porPagina);

        $avisos->getCollection()->transform(fn (Aviso $aviso) => $this->resumo($aviso));

        return $avisos;
    }

    /** Um aviso com os números do relatório. */
    public function resumo(Aviso $aviso): array
    {
        $vistos = (int) ($aviso->vistos ?? $aviso->visualizacoes()->count());
        $fechados = (int) ($aviso->fechados ?? $aviso->visualizacoes()->whereNotNull('fechado_em')->count());
        $destinatarios = $this->totalDeDestinatarios($aviso);

        return [
            'id' => $aviso->id,
            // O texto do relatório é o que a pessoa leu de fato: variáveis resolvidas.
            'titulo' => $this->personalizar($aviso->titulo),
            'mensagem' => $this->personalizar($aviso->mensagem),
            // E também o original, para o admin ver quais variáveis foram usadas.
            'titulo_original' => $aviso->titulo,
            'mensagem_original' => $aviso->mensagem,
            'autor_nome' => $aviso->autor_nome,
            'publicado_em' => $aviso->created_at?->format('d/m/Y H:i'),
            'encerrado_em' => $aviso->encerrado_em?->format('d/m/Y H:i'),
            'expira_em' => $aviso->expira_em?->format('Y-m-d\TH:i'),
            'expira_em_label' => $aviso->expira_em?->format('d/m/Y H:i'),
            'expirado' => $aviso->expirado(),
            'publicos' => array_map(fn (PublicoMala $p) => [
                'value' => $p->value,
                'label' => $p->label(),
            ], $aviso->publicosAlvo() ?: self::PUBLICO_PADRAO),
            'ativo' => $aviso->ativo(),
            'destinatarios' => $destinatarios,
            'vistos' => $vistos,
            'fechados' => $fechados,
            // Quem ainda não viu, entre os orientadores ativos de hoje.
            'nao_vistos' => max(0, $destinatarios - $vistos),
        ];
    }

    /**
     * Quem viu, quem fechou e quem ainda não viu este aviso.
     *
     * A lista é o público de hoje (o que o aviso mira) MAIS quem já registrou
     * visualização — assim uma conta desativada depois de ler não some do
     * relatório nem faz as contagens baterem errado.
     */
    public function leitores(Aviso $aviso, ?string $situacao = null, ?string $busca = null, int $porPagina = 20): LengthAwarePaginator
    {
        $pagina = $this->queryLeitores($aviso, $situacao, $busca)->paginate($porPagina);

        $pagina->getCollection()->transform(fn ($pessoa) => $this->linhaLeitor($pessoa));

        return $pagina;
    }

    /** CSV do mesmo recorte da listagem. */
    public function exportarCsv(Aviso $aviso, ?string $situacao = null, ?string $busca = null): string
    {
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}"); // BOM: faz o Excel reconhecer os acentos
        fputcsv($saida, self::CABECALHO_CSV, ';');

        $this->queryLeitores($aviso, $situacao, $busca)
            ->chunk(500, function ($pessoas) use ($saida) {
                foreach ($pessoas as $pessoa) {
                    $linha = $this->linhaLeitor($pessoa);
                    fputcsv($saida, [
                        $linha['nome'],
                        $linha['email'],
                        $linha['situacao_label'],
                        $linha['visto_em'] ?? '',
                        $linha['fechado_em'] ?? '',
                    ], ';');
                }
            });

        rewind($saida);
        $csv = stream_get_contents($saida);
        fclose($saida);

        return $csv;
    }

    /** Recorte compartilhado pela listagem e pelo CSV. */
    private function queryLeitores(Aviso $aviso, ?string $situacao, ?string $busca): Builder
    {
        return User::query()
            ->leftJoin('aviso_visualizacoes as v', function ($join) use ($aviso) {
                $join->on('v.user_id', '=', 'users.id')->where('v.aviso_id', '=', $aviso->id);
            })
            ->where(function (Builder $q) use ($aviso) {
                // O público de hoje MAIS quem já registrou visualização: conta
                // desativada depois de ler não some do relatório.
                $ids = $this->publicos
                    ->queryUniao($aviso->publicosAlvo() ?: self::PUBLICO_PADRAO)
                    ->select('users.id');

                $q->whereIn('users.id', $ids)->orWhereNotNull('v.visto_em');
            })
            ->when($busca, fn (Builder $q, string $termo) => $q->where(fn (Builder $b) => $b
                ->where('users.name', 'like', "%{$termo}%")
                ->orWhere('users.email', 'like', "%{$termo}%")))
            ->when($situacao === 'fechado', fn (Builder $q) => $q->whereNotNull('v.fechado_em'))
            ->when($situacao === 'visto', fn (Builder $q) => $q->whereNotNull('v.visto_em')->whereNull('v.fechado_em'))
            ->when($situacao === 'nao_visto', fn (Builder $q) => $q->whereNull('v.visto_em'))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->select('users.id', 'users.name', 'users.email', 'v.visto_em', 'v.fechado_em');
    }

    /** @return array<string, mixed> */
    private function linhaLeitor(object $pessoa): array
    {
        $fechado = $pessoa->fechado_em !== null;
        $visto = $pessoa->visto_em !== null;
        $situacao = $fechado ? 'fechado' : ($visto ? 'visto' : 'nao_visto');

        return [
            'id' => $pessoa->id,
            'nome' => $pessoa->name,
            'email' => $pessoa->email,
            'situacao' => $situacao,
            'situacao_label' => self::SITUACOES[$situacao],
            'visto_em' => $visto ? Carbon::parse($pessoa->visto_em)->format('d/m/Y H:i') : null,
            'fechado_em' => $fechado ? Carbon::parse($pessoa->fechado_em)->format('d/m/Y H:i') : null,
        ];
    }

    private function comoData(?CarbonInterface $data): string
    {
        return $data ? $data->format('d/m/Y').' às '.$data->format('H:i') : self::SEM_DATA;
    }

    /** "45 minutos", "2 horas e 10 minutos"… contados a partir de agora. */
    private function tempoRestante(): string
    {
        $prazo = $this->inscricoes->prazo();

        if (! $prazo || $prazo->isPast()) {
            return 'menos de um minuto';
        }

        $minutos = (int) ceil(now()->diffInMinutes($prazo, absolute: true));

        if ($minutos < 60) {
            return $minutos.($minutos === 1 ? ' minuto' : ' minutos');
        }

        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;
        $texto = $horas.($horas === 1 ? ' hora' : ' horas');

        return $resto === 0 ? $texto : $texto.' e '.$resto.($resto === 1 ? ' minuto' : ' minutos');
    }
}
