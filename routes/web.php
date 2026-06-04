<?php

use Illuminate\Support\Facades\Route;

/*
| A SPA React controla o roteamento do cliente. Qualquer rota que não seja
| da API (api/*) nem o health check (up) devolve a casca da SPA; o React
| Router decide qual tela exibir.
*/
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|up).*$');
