<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aviso diário (07:00, horário de Campo Grande) ao suporte sobre mensagens do
// chat ainda não respondidas. Requer `php artisan schedule:run` no cron (deploy).
Schedule::command('chat:alertar-pendentes')
    ->dailyAt('07:00')
    ->timezone('America/Campo_Grande');

// Faxina das sessões paradas há mais do que o tempo de vida (5 h) e do cache
// vencido em banco. O Laravel também limpa por sorteio, dentro das
// requisições; aqui a faxina tem hora certa e não pesa em quem está usando.
// Com várias EC2 rodando o cron, só uma faz (o lock mora no cache em banco).
Schedule::command('sessoes:limpar')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
