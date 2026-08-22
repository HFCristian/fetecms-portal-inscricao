<?php

namespace App\Services;

use App\Enums\ProjetoStatus;
use App\Enums\PublicoMala;
use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Enums\StatusDestinatario;
use App\Enums\StatusMala;
use App\Jobs\EnviarMalaDireta;
use App\Models\MalaDireta;
use App\Models\MalaDiretaDestinatario;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Mala direta do admin: resolve o público-alvo, congela a lista de
 * destinatários, dispara os e-mails pela fila e devolve o relatório.
 *
 * A lista NUNCA é recalculada depois do disparo — o que vale é o snapshot
 * gravado em `mala_direta_destinatarios`.
 */
class MalaDiretaService
{
    /** Origem de quem foi digitado/importado à mão, e não veio de um público. */
    public const ORIGEM_PERSONALIZADO = 'personalizado';

    /** Teto de e-mails colados/importados de uma vez (evita CSV monstro). */
    public const MAX_PERSONALIZADOS = 5000;

    /** Como o comunicado trata quem não tem nome conhecido. */
    public const TRATAMENTO_PADRAO = 'participante';

    /**
     * Variáveis aceitas no corpo da mensagem, na ordem em que a tela as oferece.
     * Esta lista é a fonte única: alimenta os botões do formulário e o laço de
     * substituição do `personalizar()`.
     *
     * @var array<int, array{chave: string, rotulo: string, descricao: string}>
     */
    public const VARIAVEIS = [
        ['chave' => 'nome', 'rotulo' => 'Primeiro nome', 'descricao' => 'De "Ana Souza", vira "Ana".'],
        ['chave' => 'nome_completo', 'rotulo' => 'Nome completo', 'descricao' => 'O nome como está no cadastro.'],
        ['chave' => 'email', 'rotulo' => 'E-mail', 'descricao' => 'O endereço de quem recebe.'],
    ];

    /** Colunas do CSV de destinatários, na ordem em que aparecem. */
    private const CABECALHO_CSV = [
        'Nome', 'E-mail', 'Papel', 'Origem',
        'Projetos cadastrados', 'Qtd. de projetos', 'Situação', 'Detalhe',
    ];

    /**
     * Monta a lista final de destinatários, deduplicada por e-mail: quem cai em
     * dois públicos (ou também está na lista personalizada) recebe uma vez só,
     * guardando todas as origens.
     *
     * @param  array<int, string>  $publicos  valores de PublicoMala
     * @param  array<int, array{email?: string|null, nome?: string|null}|string>  $personalizados
     * @return Collection<int, array<string, mixed>>
     */
    public function resolver(array $publicos, array $personalizados = []): Collection
    {
        $porEmail = [];

        foreach ($this->usuariosPorPublico($publicos) as $email => $dados) {
            $porEmail[$email] = $dados;
        }

        foreach ($this->normalizarPersonalizados($personalizados) as $email => $dados) {
            if (isset($porEmail[$email])) {
                $porEmail[$email]['origens'][] = self::ORIGEM_PERSONALIZADO;

                continue;
            }
            $porEmail[$email] = $dados;
        }

        return collect($porEmail)
            ->values()
            ->sortBy([
                // Inválidos primeiro: são o que o admin precisa corrigir antes de enviar.
                fn (array $d) => $d['status'] === StatusDestinatario::Invalido->value ? 0 : 1,
                fn (array $d) => mb_strtolower((string) ($d['nome'] ?: $d['email'])),
            ])
            ->values();
    }

    /**
     * Quantos destinatários cada público traz sozinho (antes da deduplicação).
     *
     * @param  array<int, string>  $publicos
     * @return array<string, int>
     */
    public function totaisPorPublico(array $publicos): array
    {
        $totais = [];
        foreach ($this->publicosValidos($publicos) as $publico) {
            $totais[$publico->value] = $this->queryPublico($publico)->count();
        }

        return $totais;
    }

    /**
     * Cria a mala e dispara os e-mails. A lista é congelada aqui dentro, na
     * mesma transação, para o relatório bater com o que foi enviado.
     *
     * @param  array<string, mixed>  $dados  nome, justificativa, solicitante, assunto,
     *                                       corpo, publicos, destinatarios
     */
    public function criar(array $dados, User $autor): MalaDireta
    {
        $publicos = array_values(array_unique($dados['publicos'] ?? []));
        $personalizados = $dados['destinatarios'] ?? [];
        $lista = $this->resolver($publicos, $personalizados);

        $mala = DB::transaction(function () use ($dados, $publicos, $personalizados, $lista, $autor) {
            $mala = MalaDireta::create([
                'nome' => $dados['nome'],
                'justificativa' => $dados['justificativa'],
                'solicitante' => $dados['solicitante'] ?? null,
                'assunto' => $dados['assunto'],
                'corpo' => $dados['corpo'],
                'publicos' => $publicos,
                'emails_personalizados' => count($personalizados),
                'status' => StatusMala::Enviando,
                'user_id' => $autor->id,
                'autor_nome' => $autor->name,
                'autor_email' => $autor->email,
                'enviado_em' => now(),
            ]);

            $agora = now();
            foreach ($lista->chunk(200) as $bloco) {
                MalaDiretaDestinatario::insert($bloco->map(fn (array $d) => [
                    'mala_direta_id' => $mala->id,
                    'user_id' => $d['user_id'],
                    'email' => $d['email'],
                    'nome' => $d['nome'],
                    'papel' => $d['papel'],
                    'origens' => json_encode(array_values(array_unique($d['origens']))),
                    'projetos_total' => $d['projetos_total'],
                    'projetos_titulos' => json_encode($d['projetos_titulos']),
                    'status' => $d['status'],
                    'erro' => $d['erro'],
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ])->all());
            }

            return $mala;
        });

        $this->enfileirar($mala);

        return $mala;
    }

    /** Recoloca na fila os destinatários que falharam (não mexe nos inválidos). */
    public function reenviarFalhas(MalaDireta $mala): int
    {
        $recolocados = $mala->destinatarios()
            ->where('status', StatusDestinatario::Falha)
            ->update(['status' => StatusDestinatario::Pendente, 'erro' => null]);

        if ($recolocados > 0) {
            $mala->update(['status' => StatusMala::Enviando, 'concluido_em' => null]);
            $this->enfileirar($mala);
        }

        return $recolocados;
    }

    /** Enfileira um job por destinatário pendente — falha de um não derruba os outros. */
    private function enfileirar(MalaDireta $mala): void
    {
        $mala->destinatarios()
            ->where('status', StatusDestinatario::Pendente)
            ->pluck('id')
            ->each(fn (int $id) => EnviarMalaDireta::dispatch($id));

        // Mala só de e-mails inválidos já nasce concluída (nada foi para a fila).
        $this->concluirSePronta($mala);
    }

    /** Fecha a mala quando ninguém mais está na fila. Chamado ao fim de cada job. */
    public function concluirSePronta(MalaDireta $mala): void
    {
        $pendentes = $mala->destinatarios()->where('status', StatusDestinatario::Pendente)->exists();

        if (! $pendentes && $mala->status !== StatusMala::Concluida) {
            $mala->update(['status' => StatusMala::Concluida, 'concluido_em' => now()]);
        }
    }

    /**
     * Troca as variáveis do corpo pelos dados do destinatário. Aceita
     * `{{nome}}` e `{{ nome }}`; sem nome conhecido, cumprimenta genericamente.
     */
    public function personalizar(string $corpo, MalaDiretaDestinatario $destinatario): string
    {
        $valores = [
            'nome' => $destinatario->primeiroNome() ?? self::TRATAMENTO_PADRAO,
            'nome_completo' => $destinatario->nome ?: self::TRATAMENTO_PADRAO,
            'email' => $destinatario->email,
        ];

        foreach (self::VARIAVEIS as $variavel) {
            $chave = $variavel['chave'];
            $corpo = preg_replace('/\{\{\s*'.$chave.'\s*\}\}/u', $valores[$chave], $corpo);
        }

        return $corpo;
    }

    /** Malas disparadas, da mais recente para a mais antiga. */
    public function listar(int $porPagina = 20): LengthAwarePaginator
    {
        return MalaDireta::query()
            ->withCount([
                'destinatarios as total_destinatarios',
                'destinatarios as total_enviados' => fn ($q) => $q->where('status', StatusDestinatario::Enviado),
                'destinatarios as total_falhas' => fn ($q) => $q->where('status', StatusDestinatario::Falha),
                'destinatarios as total_invalidos' => fn ($q) => $q->where('status', StatusDestinatario::Invalido),
            ])
            ->orderByRaw('COALESCE(enviado_em, created_at) DESC')
            ->orderByDesc('id')
            ->paginate($porPagina);
    }

    /**
     * Destinatários de uma mala já disparada, opcionalmente filtrados por
     * situação (é como o relatório mostra só as falhas).
     *
     * @return HasMany<MalaDiretaDestinatario, MalaDireta>
     */
    public function queryDestinatarios(MalaDireta $mala, ?string $status = null): HasMany
    {
        return $mala->destinatarios()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'falha' THEN 0 WHEN 'invalido' THEN 1 ELSE 2 END")
            ->orderBy('nome')
            ->orderBy('email');
    }

    /**
     * CSV (UTF-8 com BOM, ";") da lista de destinatários — serve tanto para a
     * prévia (antes de enviar) quanto para o relatório da mala.
     *
     * @param  iterable<int, array<string, mixed>|MalaDiretaDestinatario>  $destinatarios
     */
    public function exportarCsv(iterable $destinatarios): string
    {
        $saida = fopen('php://temp', 'r+');
        fwrite($saida, "\u{FEFF}"); // BOM: faz o Excel reconhecer os acentos
        fputcsv($saida, self::CABECALHO_CSV, ';');

        foreach ($destinatarios as $destinatario) {
            fputcsv($saida, $this->linhaCsv($destinatario), ';');
        }

        rewind($saida);
        $csv = stream_get_contents($saida);
        fclose($saida);

        return $csv;
    }

    /**
     * @param  array<string, mixed>|MalaDiretaDestinatario  $d
     * @return array<int, string>
     */
    private function linhaCsv(array|MalaDiretaDestinatario $d): array
    {
        $dados = $d instanceof MalaDiretaDestinatario
            ? [
                'nome' => $d->nome,
                'email' => $d->email,
                'papel' => $d->papel,
                'origens' => $d->origens ?? [],
                'projetos_titulos' => $d->projetos_titulos ?? [],
                'projetos_total' => $d->projetos_total,
                'status' => $d->status->value,
                'erro' => $d->erro,
            ]
            : $d;

        return [
            (string) ($dados['nome'] ?? ''),
            (string) $dados['email'],
            $dados['papel'] ? (Role::tryFrom($dados['papel'])?->label() ?? $dados['papel']) : '',
            implode(' · ', array_map(
                fn (string $o) => $this->rotuloOrigem($o),
                $dados['origens'] ?? [],
            )),
            implode(' | ', $dados['projetos_titulos'] ?? []),
            (string) ($dados['projetos_total'] ?? 0),
            StatusDestinatario::from($dados['status'])->label(),
            (string) ($dados['erro'] ?? ''),
        ];
    }

    public function rotuloOrigem(string $origem): string
    {
        return $origem === self::ORIGEM_PERSONALIZADO
            ? 'Lista personalizada'
            : (PublicoMala::tryFrom($origem)?->label() ?? $origem);
    }

    /**
     * Usuários dos públicos escolhidos, indexados pelo e-mail em minúsculas.
     *
     * @param  array<int, string>  $publicos
     * @return array<string, array<string, mixed>>
     */
    private function usuariosPorPublico(array $publicos): array
    {
        $origens = [];
        foreach ($this->publicosValidos($publicos) as $publico) {
            foreach ($this->queryPublico($publico)->pluck('id') as $id) {
                $origens[$id][] = $publico->value;
            }
        }

        if ($origens === []) {
            return [];
        }

        $lista = [];
        User::query()
            ->whereIn('id', array_keys($origens))
            ->with(['projetos:id,user_id,titulo'])
            ->chunk(500, function ($usuarios) use (&$lista, $origens) {
                foreach ($usuarios as $usuario) {
                    $lista[mb_strtolower($usuario->email)] = $this->linhaUsuario($usuario, $origens[$usuario->id]);
                }
            });

        return $lista;
    }

    /**
     * @param  array<int, string>  $origens
     * @return array<string, mixed>
     */
    private function linhaUsuario(User $usuario, array $origens): array
    {
        $titulos = $usuario->isOrientador()
            ? $usuario->projetos->pluck('titulo')->filter()->values()->all()
            : [];

        return [
            'user_id' => $usuario->id,
            'email' => $usuario->email,
            'nome' => $usuario->name,
            'papel' => $usuario->role?->value,
            'origens' => $origens,
            'projetos_total' => count($titulos),
            'projetos_titulos' => $titulos,
            'status' => StatusDestinatario::Pendente->value,
            'erro' => null,
        ];
    }

    /**
     * E-mails digitados/importados: valida o formato, deduplica e, quando o
     * e-mail é de alguém da base, aproveita nome/papel/projetos do cadastro.
     *
     * @param  array<int, array{email?: string|null, nome?: string|null}|string>  $personalizados
     * @return array<string, array<string, mixed>>
     */
    private function normalizarPersonalizados(array $personalizados): array
    {
        $entradas = [];
        foreach (array_slice($personalizados, 0, self::MAX_PERSONALIZADOS) as $item) {
            $email = mb_strtolower(trim(is_array($item) ? (string) ($item['email'] ?? '') : (string) $item));
            $nome = is_array($item) ? trim((string) ($item['nome'] ?? '')) : '';
            if ($email === '') {
                continue;
            }
            // Primeira ocorrência vence; a repetida só completa o nome que faltava.
            $entradas[$email] ??= ['email' => $email, 'nome' => $nome];
            if ($entradas[$email]['nome'] === '' && $nome !== '') {
                $entradas[$email]['nome'] = $nome;
            }
        }

        if ($entradas === []) {
            return [];
        }

        $conhecidos = User::query()
            ->whereIn('email', array_keys($entradas))
            ->with(['projetos:id,user_id,titulo'])
            ->get()
            ->keyBy(fn (User $u) => mb_strtolower($u->email));

        $lista = [];
        foreach ($entradas as $email => $entrada) {
            if ($usuario = $conhecidos->get($email)) {
                $lista[$email] = $this->linhaUsuario($usuario, [self::ORIGEM_PERSONALIZADO]);

                continue;
            }

            $invalido = Validator::make(['email' => $email], ['email' => 'email:filter'])->fails();
            $lista[$email] = [
                'user_id' => null,
                'email' => $email,
                'nome' => $entrada['nome'] ?: null,
                'papel' => null,
                'origens' => [self::ORIGEM_PERSONALIZADO],
                'projetos_total' => 0,
                'projetos_titulos' => [],
                'status' => $invalido ? StatusDestinatario::Invalido->value : StatusDestinatario::Pendente->value,
                'erro' => $invalido ? 'Endereço de e-mail inválido.' : null,
            ];
        }

        return $lista;
    }

    /**
     * @param  array<int, string>  $publicos
     * @return array<int, PublicoMala>
     */
    private function publicosValidos(array $publicos): array
    {
        return array_values(array_filter(array_map(
            fn ($valor) => $valor instanceof PublicoMala ? $valor : PublicoMala::tryFrom((string) $valor),
            $publicos,
        )));
    }

    /**
     * Consulta de cada público. Sempre só contas ativas e não demo — conta de
     * teste não recebe comunicado.
     *
     * @return Builder<User>
     */
    public function queryPublico(PublicoMala $publico): Builder
    {
        $base = User::query()->where('is_active', true)->where('is_demo', false);

        $submetidos = [
            ProjetoStatus::Submetido->value,
            ProjetoStatus::Aprovado->value,
            ProjetoStatus::Rejeitado->value,
        ];

        return match ($publico) {
            PublicoMala::Todos => $base->whereIn('role', [Role::Orientador, Role::Avaliador]),
            PublicoMala::Orientadores => $base->where('role', Role::Orientador),
            PublicoMala::Avaliadores => $base->where('role', Role::Avaliador),
            PublicoMala::OrientadoresRascunho => $base->where('role', Role::Orientador)
                ->whereHas('projetos', fn ($q) => $q->where('status', ProjetoStatus::Rascunho)),
            PublicoMala::OrientadoresSubmetidos => $base->where('role', Role::Orientador)
                ->whereHas('projetos', fn ($q) => $q->whereIn('status', $submetidos)),
            PublicoMala::AvaliadoresPendentes => $base->where('role', Role::Avaliador)
                ->whereHas('avaliacoes', fn ($q) => $q->where('status', StatusAvaliacao::EmAndamento)),
            PublicoMala::AvaliadoresConcluidas => $base->where('role', Role::Avaliador)
                ->whereHas('avaliacoes', fn ($q) => $q->where('status', StatusAvaliacao::Concluida)),
        };
    }
}
