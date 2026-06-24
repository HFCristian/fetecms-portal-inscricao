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
