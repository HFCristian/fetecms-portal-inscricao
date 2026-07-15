<?php

namespace App\Console\Commands;

use App\Services\DistribuicaoService;
use Illuminate\Console\Command;

/**
 * Distribui os projetos submetidos aos avaliadores (E7). Idempotente: pode ser
 * rodado várias vezes — só completa o que falta. Mesmo motor do botão do admin.
 */
class DistribuirAvaliacoes extends Command
{
    protected $signature = 'avaliacao:distribuir';

    protected $description = 'Distribui projetos submetidos aos avaliadores (por subárea/área)';

    public function handle(DistribuicaoService $service): int
    {
        $relatorio = $service->distribuir();

        $this->info(sprintf(
            '%d designação(ões) criada(s); %d projeto(s) sub-coberto(s).',
            $relatorio['designadas_criadas'],
            count($relatorio['sub_cobertos']),
        ));

        return self::SUCCESS;
    }
}
