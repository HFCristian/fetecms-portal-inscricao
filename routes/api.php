<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\OrientadorController;
use App\Http\Controllers\Api\V1\PerfilController;
use App\Http\Controllers\Api\V1\ProjetoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Auth do web por cookie/CSRF (Sanctum SPA, mesma origem); mobile usará
| token Bearer na mesma API. Regra de negócio nos Services.
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'data' => ['status' => 'ok', 'service' => 'fetecms-api'],
    ]));

    // Públicas (com rate limiting contra brute force)
    Route::post('/orientadores', [OrientadorController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1');

    // Catálogos (leitura pública — dados de referência)
    Route::prefix('catalogos')->group(function () {
        Route::get('/edicoes', [CatalogoController::class, 'edicoes']);
        Route::get('/categorias', [CatalogoController::class, 'categorias']);
        Route::get('/areas', [CatalogoController::class, 'areas']);
        Route::get('/subareas', [CatalogoController::class, 'subareas']);
        Route::get('/estados', [CatalogoController::class, 'estados']);
        Route::get('/cidades', [CatalogoController::class, 'cidades']);
        Route::get('/instituicoes', [CatalogoController::class, 'instituicoes']);
    });

    // Autenticadas (sessão Sanctum SPA ou token)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/perfil', [PerfilController::class, 'show']);
        Route::put('/perfil', [PerfilController::class, 'update']);

        Route::apiResource('projetos', ProjetoController::class);
    });
});
