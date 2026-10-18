<?php

namespace App\Console\Commands;

use App\Services\SessoesService;
use Illuminate\Console\Command;

/**
 * O raio-X para quando o portal "trava" ou "derruba" alguém: configuração da
 * sessão (que precisa bater entre as instâncias), a tabela de sessões, o
 * cache, a fila e — no PostgreSQL — conexões e locks. Só lê; não muda nada.
 * O roteiro completo está em docs/DEPLOY_AWS.md, §13.
 */
class DiagnosticarSessoes extends Command
{
    protected $signature = 'sessoes:diagnostico';

    protected $description = 'Mostra a configuração e o estado das sessões, do cache, da fila e do banco';

    public function handle(SessoesService $sessoes): int
    {
        $d = $sessoes->diagnostico();
        $c = $d['config'];
        $simNao = fn (bool $v) => $v ? 'sim' : 'não';

        $this->line('<options=bold>Configuração</>');
        $this->table([], [
            ['Ambiente', $c['ambiente'].($c['debug'] ? ' (APP_DEBUG ligado)' : '')],
            ['Sessão', "{$c['driver']} · vida útil {$c['vida_util_minutos']} min · sorteio da faxina {$c['sorteio']}"],
            ['Cookie', "{$c['cookie']} · seguro: {$simNao($c['cookie_seguro'])} · same_site: {$c['same_site']} · domínio: ".($c['dominio'] ?: 'só o host')],
            ['Sanctum (stateful)', $c['sanctum_stateful'] ?: '—'],
            ['Cache · fila · banco', "{$c['cache']} · {$c['fila']} · {$c['banco']}"],
            ['Config em cache', $simNao($c['config_em_cache']).' (mudou o .env? optimize:clear && optimize)'],
            ['APP_KEY (impressão digital)', ($c['app_key'] ?? 'VAZIA').' — precisa ser igual em todas as instâncias'],
        ]);

        if ($s = $d['sessoes']) {
            $f = $s['por_faixa'];
            $this->line('<options=bold>Sessões</>');
            $this->table([], [
                ['Total', "{$s['total']} ({$s['autenticadas']} com login, {$s['anonimas']} sem login)"],
                ['Última atividade', "até 5 min: {$f['ate_5_min']} · 5–60 min: {$f['de_5_a_60_min']} · 1 h até o limite: {$f['de_1h_ao_limite']} · expiradas: {$f['expiradas']}"],
                ['Contas com mais sessões (1 h)', $this->lista($s['contas_com_mais_sessoes'], 'email')],
                ['IPs com mais sessões sem login (1 h)', $this->lista($s['ips_anonimos_na_ultima_hora'], 'ip')],
                ['Maior sessão', "{$s['maior_payload_kb']} KB"],
            ]);
        } else {
            $this->line('Sessões: fora do banco ou tabela inexistente — sem estatísticas.');
        }

        $linhas = [];
        if ($cache = $d['cache']) {
            $linhas[] = ['Cache em banco', "{$cache['total']} entradas ({$cache['vencidas']} vencidas)"];
        }
        if ($fila = $d['fila']) {
            $linhas[] = ['Fila', "{$fila['pendentes']} pendente(s)"
                .($fila['mais_antigo_minutos'] !== null ? " · a mais antiga há {$fila['mais_antigo_minutos']} min" : '')
                .($fila['falhas_24h'] !== null ? " · {$fila['falhas_24h']} falha(s) nas últimas 24 h" : '')];
        }
        if ($b = $d['banco']) {
            $estados = collect($b['por_estado'])->map(fn ($n, $e) => "{$e}: {$n}")->implode(', ');
            $linhas[] = ['Conexões PostgreSQL', "{$b['conexoes']} de {$b['max_conexoes']} ({$estados}) · esperando lock: {$b['esperando_lock']}"];
            $linhas[] = ['Tamanho (MB)', collect($b['tamanho_mb'])->map(fn ($mb, $t) => "{$t}: ".($mb ?? '—'))->implode(' · ')];
        }
        if ($linhas !== []) {
            $this->line('<options=bold>Cache, fila e banco</>');
            $this->table([], $linhas);
        }

        if ($d['alertas'] === []) {
            $this->info('Nenhum ponto de atenção encontrado nas sessões, no cache, na fila e no banco.');
        } else {
            $this->line('<options=bold>Pontos de atenção</>');
            foreach ($d['alertas'] as $alerta) {
                $this->warn('• '.$alerta);
            }
        }

        $this->newLine();
        $this->line('Fora do Laravel (PHP-FPM, Nginx, ALB): veja o roteiro em docs/DEPLOY_AWS.md, §13.');

        return self::SUCCESS;
    }

    /** @param  list<array<string, mixed>>  $itens */
    private function lista(array $itens, string $campo): string
    {
        return $itens === []
            ? '—'
            : collect($itens)->map(fn ($i) => "{$i[$campo]} ({$i['total']})")->implode(', ');
    }
}
