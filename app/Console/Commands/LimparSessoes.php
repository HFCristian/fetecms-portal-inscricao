<?php

namespace App\Console\Commands;

use App\Services\SessoesService;
use Illuminate\Console\Command;

/**
 * Faxina das sessões paradas há mais do que o tempo de vida (5 horas) e das
 * entradas vencidas do cache em banco. Agendado de 15 em 15 minutos
 * (routes/console.php); rodar à mão também é seguro — sessão expirada já não
 * autentica ninguém.
 */
class LimparSessoes extends Command
{
    protected $signature = 'sessoes:limpar
        {--horas= : Apaga as sessões paradas há mais do que estas horas (padrão: SESSION_LIFETIME)}';

    protected $description = 'Apaga as sessões inativas há mais do que o tempo de vida e o cache vencido';

    public function handle(SessoesService $sessoes): int
    {
        if (! $sessoes->usaBanco()) {
            $this->warn('As sessões não estão no banco (SESSION_DRIVER='.config('session.driver').'): nada a limpar aqui.');

            return self::SUCCESS;
        }

        $horas = $this->option('horas');

        if ($horas !== null && (! is_numeric($horas) || (float) $horas <= 0)) {
            $this->error('--horas precisa ser um número maior que zero.');

            return self::INVALID;
        }

        $minutos = $horas === null ? $sessoes->vidaUtilMinutos() : (int) round((float) $horas * 60);
        $apagadas = $sessoes->limpar($minutos);
        $cache = $sessoes->limparCacheVencido();

        $this->info(sprintf(
            '%d sessão(ões) parada(s) há mais de %s apagada(s); %d entrada(s) vencida(s) do cache.',
            $apagadas,
            $minutos % 60 === 0 ? ($minutos / 60).' h' : $minutos.' min',
            $cache,
        ));

        return self::SUCCESS;
    }
}
