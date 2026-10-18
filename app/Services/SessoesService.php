<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A faxina e o raio-X das **sessões** do portal (Sprint 244).
 *
 * A sessão mora no banco (`SESSION_DRIVER=database`): é o que deixa várias
 * instâncias atrás do ALB enxergarem o mesmo login. O preço é que cada
 * requisição lê e regrava uma linha de `sessions`, e a tabela só encolhe
 * quando alguém apaga o que expirou — o Laravel faz isso por sorteio (2% das
 * requisições), dentro da requisição de um usuário qualquer.
 *
 * Aqui a faxina passa a ter hora certa: `sessoes:limpar`, agendado de 15 em 15
 * minutos, apaga a sessão parada há mais do que o tempo de vida (5 horas,
 * `SESSION_LIFETIME=300`) e as entradas vencidas do cache em banco. O
 * diagnóstico (`sessoes:diagnostico`) junta, numa tela, o que se pergunta
 * quando o portal "trava" ou "derruba" alguém: a configuração que precisa
 * bater entre as instâncias, quantas sessões há e de quem, a fila e, no
 * PostgreSQL, conexões e locks.
 */
class SessoesService
{
    /** Uma conta em tantos aparelhos ao mesmo tempo já divide o limite da API. */
    public const APARELHOS_POR_CONTA = 4;

    /** Sessões sem login vindas de um IP numa hora — acima disso, é robô. */
    public const ANONIMAS_POR_IP = 300;

    /** Expiradas que o sorteio deixa para trás sem ser problema. */
    public const EXPIRADAS_TOLERADAS = 500;

    /** Tamanho de cada lote do apagamento, para não segurar a tabela. */
    private const LOTE = 5000;

    public function vidaUtilMinutos(): int
    {
        return max(1, (int) config('session.lifetime'));
    }

    public function usaBanco(): bool
    {
        return config('session.driver') === 'database'
            && Schema::connection($this->conexao()->getName())->hasTable($this->tabela());
    }

    /**
     * Apaga as sessões paradas há mais de `$minutos` (padrão: o tempo de vida).
     * Sessão expirada já não autentica ninguém — o Laravel a ignora na leitura —,
     * então apagar é só faxina: quem estava nela já precisaria entrar de novo.
     */
    public function limpar(?int $minutos = null): int
    {
        if (! $this->usaBanco()) {
            return 0;
        }

        $limite = now()->subMinutes($minutos ?? $this->vidaUtilMinutos())->getTimestamp();

        return $this->apagarEmLotes($this->sessoes()->where('last_activity', '<', $limite), 'id');
    }

    /** Entradas do cache em banco que já venceram (o rate limit deixa muitas). */
    public function limparCacheVencido(): int
    {
        if (config('cache.default') !== 'database') {
            return 0;
        }

        $tabela = (string) config('cache.stores.database.table', 'cache');
        $conexao = DB::connection(config('cache.stores.database.connection'));

        if (! Schema::connection($conexao->getName())->hasTable($tabela)) {
            return 0;
        }

        return $this->apagarEmLotes($conexao->table($tabela)->where('expiration', '<', now()->getTimestamp()), 'key');
    }

    /**
     * O raio-X. Cada parte é independente: uma consulta que falha (a do
     * PostgreSQL num banco sem permissão de `pg_stat_activity`, por exemplo)
     * vira `null` naquela parte e não derruba o resto.
     *
     * @return array<string, mixed>
     */
    public function diagnostico(): array
    {
        $dados = [
            'config' => $this->configuracao(),
            'sessoes' => $this->tentar(fn () => $this->usaBanco() ? $this->estatisticasSessoes() : null),
            'cache' => $this->tentar(fn () => $this->estatisticasCache()),
            'fila' => $this->tentar(fn () => $this->estatisticasFila()),
            'banco' => $this->tentar(fn () => $this->estatisticasPostgres()),
        ];

        $dados['alertas'] = $this->alertas($dados);

        return $dados;
    }

    // --- Partes do diagnóstico -----------------------------------------

    /** @return array<string, mixed> */
    private function configuracao(): array
    {
        $chave = (string) config('app.key');

        return [
            'ambiente' => config('app.env'),
            'debug' => (bool) config('app.debug'),
            'driver' => config('session.driver'),
            'vida_util_minutos' => $this->vidaUtilMinutos(),
            'expira_ao_fechar' => (bool) config('session.expire_on_close'),
            'sorteio' => implode('/', (array) config('session.lottery')),
            'cookie' => config('session.cookie'),
            'cookie_seguro' => (bool) config('session.secure'),
            'same_site' => config('session.same_site'),
            'dominio' => config('session.domain'),
            'sanctum_stateful' => implode(', ', (array) config('sanctum.stateful')),
            'cache' => config('cache.default'),
            'fila' => config('queue.default'),
            'banco' => config('database.default'),
            'config_em_cache' => app()->configurationIsCached(),
            // Só uma impressão digital: comparar entre instâncias sem expor a chave.
            'app_key' => $chave === '' ? null : substr(hash('sha256', $chave), 0, 12),
        ];
    }

    /** @return array<string, mixed> */
    private function estatisticasSessoes(): array
    {
        $agora = now()->getTimestamp();
        $limite = now()->subMinutes($this->vidaUtilMinutos())->getTimestamp();
        $umaHora = $agora - 3600;

        $faixas = [
            'ate_5_min' => [$agora - 300, null],
            'de_5_a_60_min' => [$umaHora, $agora - 300],
            'de_1h_ao_limite' => [$limite, $umaHora],
            'expiradas' => [null, $limite],
        ];

        $porFaixa = [];
        foreach ($faixas as $nome => [$de, $ate]) {
            $porFaixa[$nome] = $this->sessoes()
                ->when($de !== null, fn (Builder $q) => $q->where('last_activity', '>=', $de))
                ->when($ate !== null, fn (Builder $q) => $q->where('last_activity', '<', $ate))
                ->count();
        }

        $contas = $this->sessoes()
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $umaHora)
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $emails = DB::table('users')->whereIn('id', $contas->pluck('user_id'))->pluck('email', 'id');

        return [
            'total' => $this->sessoes()->count(),
            'autenticadas' => $this->sessoes()->whereNotNull('user_id')->count(),
            'anonimas' => $this->sessoes()->whereNull('user_id')->count(),
            'por_faixa' => $porFaixa,
            'contas_com_mais_sessoes' => $contas->map(fn ($c) => [
                'email' => $emails[$c->user_id] ?? '#'.$c->user_id,
                'total' => (int) $c->total,
            ])->all(),
            'ips_anonimos_na_ultima_hora' => $this->sessoes()
                ->whereNull('user_id')
                ->where('last_activity', '>=', $umaHora)
                ->groupBy('ip_address')
                ->selectRaw('ip_address, count(*) as total')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn ($l) => ['ip' => $l->ip_address ?? '?', 'total' => (int) $l->total])
                ->all(),
            'maior_payload_kb' => round(((int) $this->sessoes()->max(DB::raw('length(payload)'))) / 1024, 1),
        ];
    }

    /** @return array<string, int>|null */
    private function estatisticasCache(): ?array
    {
        if (config('cache.default') !== 'database') {
            return null;
        }

        $tabela = (string) config('cache.stores.database.table', 'cache');
        $conexao = DB::connection(config('cache.stores.database.connection'));

        if (! Schema::connection($conexao->getName())->hasTable($tabela)) {
            return null;
        }

        return [
            'total' => $conexao->table($tabela)->count(),
            'vencidas' => $conexao->table($tabela)->where('expiration', '<', now()->getTimestamp())->count(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function estatisticasFila(): ?array
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        $maisAntigo = DB::table('jobs')->min('created_at');

        return [
            'pendentes' => DB::table('jobs')->count(),
            'mais_antigo_minutos' => $maisAntigo === null ? null : (int) floor((now()->getTimestamp() - (int) $maisAntigo) / 60),
            'falhas_24h' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()
                : null,
        ];
    }

    /** Conexões, locks e tamanho das tabelas — só no PostgreSQL. */
    private function estatisticasPostgres(): ?array
    {
        $db = DB::connection();

        if ($db->getDriverName() !== 'pgsql') {
            return null;
        }

        $porEstado = collect($db->select(
            'select coalesce(state, \'?\') as estado, count(*) as total from pg_stat_activity where datname = current_database() group by state',
        ))->mapWithKeys(fn ($l) => [$l->estado => (int) $l->total])->all();

        $tamanho = fn (string $tabela) => Schema::hasTable($tabela)
            ? (int) ($db->selectOne('select pg_total_relation_size(?) as bytes', [$tabela])->bytes ?? 0)
            : null;

        return [
            'conexoes' => array_sum($porEstado),
            'max_conexoes' => (int) ($db->selectOne('show max_connections')->max_connections ?? 0),
            'por_estado' => $porEstado,
            'esperando_lock' => (int) ($db->selectOne(
                'select count(*) as total from pg_stat_activity where datname = current_database() and wait_event_type = \'Lock\'',
            )->total ?? 0),
            'tamanho_mb' => array_map(
                fn (?int $bytes) => $bytes === null ? null : round($bytes / 1048576, 1),
                ['sessions' => $tamanho($this->tabela()), 'cache' => $tamanho('cache'), 'jobs' => $tamanho('jobs')],
            ),
        ];
    }

    /**
     * O que merece atenção, em português, para quem está olhando o servidor.
     *
     * @param  array<string, mixed>  $d
     * @return list<string>
     */
    private function alertas(array $d): array
    {
        $alertas = [];
        $c = $d['config'];

        if ($c['vida_util_minutos'] !== 300) {
            $alertas[] = "SESSION_LIFETIME está em {$c['vida_util_minutos']} min; o combinado é 300 (5 h). Ajuste o .env e rode php artisan optimize:clear && php artisan optimize.";
        }
        if ($c['ambiente'] === 'production' && ! $c['cookie_seguro']) {
            $alertas[] = 'SESSION_SECURE_COOKIE está desligado em produção: atrás do ALB com HTTPS ele precisa ser true.';
        }
        if ($c['ambiente'] === 'production' && $c['debug']) {
            $alertas[] = 'APP_DEBUG ligado em produção: erro 500 mostra detalhes internos e deixa as respostas mais lentas.';
        }
        if ($c['driver'] !== 'database') {
            $alertas[] = "SESSION_DRIVER={$c['driver']}: com mais de uma instância atrás do ALB, a sessão precisa estar num lugar compartilhado (database ou redis).";
        }

        $s = $d['sessoes'];
        if ($s !== null) {
            if ($s['por_faixa']['expiradas'] > self::EXPIRADAS_TOLERADAS) {
                $alertas[] = "{$s['por_faixa']['expiradas']} sessões paradas há mais do que o tempo de vida ainda estão na tabela: o cron do agendador (schedule:run) está rodando? Rode php artisan sessoes:limpar.";
            }
            foreach ($s['contas_com_mais_sessoes'] as $conta) {
                if ($conta['total'] >= self::APARELHOS_POR_CONTA) {
                    $alertas[] = "{$conta['email']} usou {$conta['total']} sessões na última hora: a mesma conta em vários aparelhos divide o limite de 120 requisições/min da API, e as telas que se atualizam sozinhas passam a receber 429.";
                }
            }
            foreach ($s['ips_anonimos_na_ultima_hora'] as $ip) {
                if ($ip['total'] >= self::ANONIMAS_POR_IP) {
                    $alertas[] = "O IP {$ip['ip']} abriu {$ip['total']} sessões sem login na última hora: robô ou monitoramento batendo numa rota que cria sessão (o health check deve usar /up).";
                }
            }
        }

        $f = $d['fila'];
        if ($f !== null && ($f['mais_antigo_minutos'] ?? 0) > 15) {
            $alertas[] = "Há job na fila há {$f['mais_antigo_minutos']} min: o queue:work está no ar? (sudo systemctl status fetecms-queue)";
        }

        $b = $d['banco'];
        if ($b !== null) {
            if ($b['max_conexoes'] > 0 && $b['conexoes'] >= 0.8 * $b['max_conexoes']) {
                $alertas[] = "PostgreSQL com {$b['conexoes']} de {$b['max_conexoes']} conexões: perto do teto, uma requisição nova espera ou falha.";
            }
            if (($b['por_estado']['idle in transaction'] ?? 0) > 0) {
                $alertas[] = "{$b['por_estado']['idle in transaction']} conexão(ões) paradas no meio de uma transação: seguram locks até cair.";
            }
            if ($b['esperando_lock'] > 0) {
                $alertas[] = "{$b['esperando_lock']} consulta(s) esperando lock agora.";
            }
        }

        return $alertas;
    }

    // --- Interno ---------------------------------------------------------

    private function conexao(): Connection
    {
        return DB::connection(config('session.connection'));
    }

    private function tabela(): string
    {
        return (string) config('session.table', 'sessions');
    }

    private function sessoes(): Builder
    {
        return $this->conexao()->table($this->tabela());
    }

    /** Apaga o que a consulta acha, em lotes pela chave — nada de um DELETE gigante. */
    private function apagarEmLotes(Builder $consulta, string $chave): int
    {
        $total = 0;

        do {
            $chaves = (clone $consulta)->limit(self::LOTE)->pluck($chave);

            if ($chaves->isEmpty()) {
                break;
            }

            $total += (clone $consulta)->whereIn($chave, $chaves)->delete();
        } while ($chaves->count() === self::LOTE);

        return $total;
    }

    private function tentar(callable $parte): mixed
    {
        try {
            return $parte();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
