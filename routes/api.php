<?php

use App\Http\Controllers\Api\V1\AlunoController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvaliadorController;
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\CoorientadorController;
use App\Http\Controllers\Api\V1\DocumentoController;
use App\Http\Controllers\Api\V1\IntegranteController;
use App\Http\Controllers\Api\V1\OrientadorController;
use App\Http\Controllers\Api\V1\PerfilController;
use App\Http\Controllers\Api\V1\ProjetoController;
use App\Http\Controllers\Api\V1\ProjetoSubmissaoController;
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
    Route::post('/avaliadores', [AvaliadorController::class, 'store'])
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

        // Submissão (E6) — resumo/checklist e envio irreversível
        Route::get('projetos/{projeto}/resumo', [ProjetoSubmissaoController::class, 'resumo']);
        Route::post('projetos/{projeto}/submeter', [ProjetoSubmissaoController::class, 'submeter']);

        // Integrantes do projeto (E4)
        Route::get('projetos/{projeto}/integrantes', [IntegranteController::class, 'index']);
        Route::apiResource('projetos.alunos', AlunoController::class)->shallow();
        Route::get('projetos/{projeto}/coorientador', [CoorientadorController::class, 'show']);
        Route::put('projetos/{projeto}/coorientador', [CoorientadorController::class, 'upsert']);
        Route::delete('projetos/{projeto}/coorientador', [CoorientadorController::class, 'destroy']);

        // Documentos do projeto (E5) — upload PDF/DOCX, download autenticado
        Route::get('projetos/{projeto}/documentos', [DocumentoController::class, 'index']);
        Route::post('projetos/{projeto}/documentos', [DocumentoController::class, 'store']);
        Route::get('documentos/{documento}/download', [DocumentoController::class, 'download']);
        Route::get('documentos/{documento}/preview', [DocumentoController::class, 'preview']);
        Route::delete('documentos/{documento}', [DocumentoController::class, 'destroy']);
    });
});
