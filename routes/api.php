<?php

use App\Http\Controllers\Api\V1\AdminAvaliacaoController;
use App\Http\Controllers\Api\V1\AdminAvisoController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AdminInscricoesController;
use App\Http\Controllers\Api\V1\AdminMalaDiretaController;
use App\Http\Controllers\Api\V1\AdminRegistroController;
use App\Http\Controllers\Api\V1\AlunoController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvaliadorAvaliacaoController;
use App\Http\Controllers\Api\V1\AvaliadorController;
use App\Http\Controllers\Api\V1\AvaliadorPerfilController;
use App\Http\Controllers\Api\V1\AvisoController;
use App\Http\Controllers\Api\V1\CatalogoAdminController;
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\ChatAdminController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\CoorientadorController;
use App\Http\Controllers\Api\V1\DocumentoController;
use App\Http\Controllers\Api\V1\InscricoesController;
use App\Http\Controllers\Api\V1\InstituicaoAdminController;
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

Route::prefix('v1')->middleware('throttle:120,1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'data' => ['status' => 'ok', 'service' => 'fetecms-api'],
    ]));

    // Públicas (com rate limiting contra brute force)
    Route::post('/orientadores', [OrientadorController::class, 'store'])
        ->middleware(['throttle:10,1', 'inscricoes.iniciadas']);
    // Janela de inscrição vista por quem ainda não tem conta (tela de cadastro).
    Route::get('/inscricoes/publico', [InscricoesController::class, 'show']);
    Route::post('/avaliadores', [AvaliadorController::class, 'store'])
        ->middleware('throttle:10,1');
    // O bloqueio por excesso de tentativas é feito no AuthService, por e-mail+IP e
    // só contando FALHAS (ver AuthService::MAX_TENTATIVAS). Este throttle por IP é
    // apenas a rede de proteção contra abuso automatizado — folgado o bastante para
    // não punir vários usuários legítimos atrás do mesmo IP (escola com NAT).
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:30,1');

    // Recuperação de senha (link temporário por e-mail) — rate limit contra abuso.
    Route::post('/auth/esqueci-senha', [AuthController::class, 'esqueciSenha'])
        ->middleware('throttle:6,1');
    Route::post('/auth/redefinir-senha', [AuthController::class, 'redefinirSenha'])
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
        Route::get('/palavras-chave', [CatalogoController::class, 'palavrasChave']);
    });

    // Autenticadas (sessão Sanctum SPA ou token)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        // Troca de senha (qualquer papel); rate limit contra brute force da senha atual.
        Route::put('/auth/senha', [AuthController::class, 'alterarSenha'])
            ->middleware('throttle:6,1');
        // Troca do e-mail de acesso (qualquer papel) — registrada na trilha do admin.
        Route::put('/auth/email', [AuthController::class, 'alterarEmail'])
            ->middleware('throttle:6,1');

        Route::get('/perfil', [PerfilController::class, 'show']);
        Route::put('/perfil', [PerfilController::class, 'update']);

        // Criação global (combobox "digite/crie") — autenticada e limitada.
        Route::post('/catalogos/subareas', [CatalogoController::class, 'criarSubarea'])
            ->middleware('throttle:30,1');
        Route::post('/catalogos/instituicoes', [CatalogoController::class, 'criarInstituicao'])
            ->middleware('throttle:30,1');

        // Card de aviso publicado pelo admin: consultado de tempos em tempos
        // pelo front, marcado como visto quando aparece e fechado pela pessoa.
        Route::get('/avisos/ativo', [AvisoController::class, 'ativo']);
        Route::post('/avisos/{aviso}/visto', [AvisoController::class, 'visto']);
        Route::post('/avisos/{aviso}/fechar', [AvisoController::class, 'fechar']);

        // Prazo de inscrição: quem está logado precisa saber se ainda dá para
        // escrever (é o que explica os botões desabilitados na tela do orientador).
        Route::get('/inscricoes', [InscricoesController::class, 'show']);

        // Tudo que ESCREVE em projeto passa pelo prazo de submissão: depois da
        // data-limite a área do orientador fica só de leitura (GET passa sempre,
        // e o admin também).
        Route::middleware('inscricoes.abertas')->group(function () {
            Route::apiResource('projetos', ProjetoController::class);

            // Submissão (E6) — resumo/checklist e envio irreversível
            Route::get('projetos/{projeto}/resumo', [ProjetoSubmissaoController::class, 'resumo']);
            Route::post('projetos/{projeto}/submeter', [ProjetoSubmissaoController::class, 'submeter']);
            // Desfazer a submissão (volta a rascunho) enquanto a avaliação não começou
            Route::post('projetos/{projeto}/cancelar-submissao', [ProjetoSubmissaoController::class, 'cancelar']);

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

        // Avaliação online — lado do avaliador (E7): ler, iniciar e concluir com nota
        Route::middleware('role:avaliador')->prefix('avaliacao')->group(function () {
            Route::get('/', [AvaliadorAvaliacaoController::class, 'index']);
            Route::post('/roletar', [AvaliadorAvaliacaoController::class, 'roletar'])
                ->middleware('throttle:20,1');
            Route::get('/{avaliacao}', [AvaliadorAvaliacaoController::class, 'show']);
            Route::post('/{avaliacao}/iniciar', [AvaliadorAvaliacaoController::class, 'iniciar']);
            Route::post('/{avaliacao}/rascunho', [AvaliadorAvaliacaoController::class, 'rascunho']);
            Route::post('/{avaliacao}/concluir', [AvaliadorAvaliacaoController::class, 'concluir']);
        });

        // Perfil do avaliador: estatísticas do certificado e troca da própria área
        Route::middleware('role:avaliador')->prefix('avaliador')->group(function () {
            Route::get('/perfil', [AvaliadorPerfilController::class, 'show']);
            Route::put('/perfil/classificacao', [AvaliadorPerfilController::class, 'atualizarClassificacao']);
            Route::put('/perfil/localidade', [AvaliadorPerfilController::class, 'atualizarLocalidade']);
        });

        // Chat de suporte — orientador/avaliador falam com o suporte (admin)
        Route::middleware('role:orientador,avaliador')->prefix('chat')->group(function () {
            Route::get('/conversa', [ChatController::class, 'show']);
            Route::get('/nao-lidas', [ChatController::class, 'naoLidas']);
            Route::post('/dispensar-dica', [ChatController::class, 'dispensarDica']);
            Route::post('/mensagens', [ChatController::class, 'store'])
                ->middleware('throttle:30,1');
        });

        // Administração (E8) — somente admin
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/avaliadores', [AdminController::class, 'avaliadores']);

            // Avaliação online (E7): visão por área de avaliadores e projetos submetidos
            Route::get('/avaliacao/config', [AdminAvaliacaoController::class, 'config']);
            Route::patch('/avaliacao/config', [AdminAvaliacaoController::class, 'definirLiberacao']);
            Route::patch('/avaliacao/encerramento', [AdminAvaliacaoController::class, 'definirEncerramento']);
            Route::patch('/avaliacao/minimos', [AdminAvaliacaoController::class, 'definirMinimos']);
            Route::get('/avaliacao/avaliadores', [AdminAvaliacaoController::class, 'avaliadores']);
            Route::get('/avaliacao/avaliadores/opcoes', [AdminAvaliacaoController::class, 'avaliadoresOpcoes']);
            Route::get('/avaliacao/avaliadores/exportar', [AdminAvaliacaoController::class, 'exportarAvaliadores']);
            Route::get('/avaliacao/projetos', [AdminAvaliacaoController::class, 'projetos']);
            Route::get('/avaliacao/projetos/exportar', [AdminAvaliacaoController::class, 'exportarProjetos']);
            Route::get('/avaliacao/reclassificacoes', [AdminAvaliacaoController::class, 'reclassificacoes']);
            Route::post('/avaliacao/reclassificacoes/aplicar', [AdminAvaliacaoController::class, 'aplicarReclassificacoes']);
            Route::get('/avaliacao/ranking', [AdminAvaliacaoController::class, 'ranking']);
            Route::get('/avaliacao/ranking-avaliadores', [AdminAvaliacaoController::class, 'rankingAvaliadores']);
            Route::post('/avaliacao/projetos/{projeto}/designar', [AdminAvaliacaoController::class, 'designar']);
            Route::get('/avaliacao/distribuicao', [AdminAvaliacaoController::class, 'distribuicaoConfig']);
            Route::patch('/avaliacao/distribuicao', [AdminAvaliacaoController::class, 'definirRegrasDistribuicao']);
            Route::patch('/avaliacao/distribuicao/ao-cadastrar', [AdminAvaliacaoController::class, 'definirDistribuicaoAoCadastrar']);
            Route::post('/avaliacao/distribuir', [AdminAvaliacaoController::class, 'distribuir']);
            Route::post('/avaliacao/redistribuir', [AdminAvaliacaoController::class, 'redistribuir']);
            Route::patch('/avaliacao/avaliadores/{avaliador}/limite', [AdminAvaliacaoController::class, 'limitar']);
            Route::patch('/avaliacao/avaliadores/{avaliador}/demo', [AdminAvaliacaoController::class, 'demo']);
            Route::patch('/avaliacao/avaliadores/{avaliador}/comissao', [AdminAvaliacaoController::class, 'comissao']);
            Route::post('/avaliacao/avaliadores/{avaliador}/areas-extras', [AdminAvaliacaoController::class, 'adicionarAreaExtra']);
            Route::delete('/avaliacao/avaliadores/{avaliador}/areas-extras/{extra}', [AdminAvaliacaoController::class, 'removerAreaExtra']);
            Route::delete('/avaliacao/testes', [AdminAvaliacaoController::class, 'limparTestes']);
            // Trilha de registros (submissões, cancelamentos, exclusões, e-mails)
            // Aba "Inscrições": prazo de submissão dos projetos.
            Route::get('/inscricoes', [AdminInscricoesController::class, 'show']);
            Route::patch('/inscricoes/prazo', [AdminInscricoesController::class, 'definirPrazo']);
            Route::patch('/inscricoes/inicio', [AdminInscricoesController::class, 'definirInicio']);
            // Avisos na tela dos orientadores (um ativo por vez).
            Route::get('/avisos/opcoes', [AdminAvisoController::class, 'opcoes']);
            Route::get('/avisos/ativo', [AdminAvisoController::class, 'ativo']);
            Route::post('/avisos/previa', [AdminAvisoController::class, 'previa']);
            Route::post('/avisos', [AdminAvisoController::class, 'store']);
            Route::get('/avisos', [AdminAvisoController::class, 'index']);
            Route::post('/avisos/{aviso}/encerrar', [AdminAvisoController::class, 'encerrar']);
            Route::get('/avisos/{aviso}', [AdminAvisoController::class, 'show']);
            Route::get('/avisos/{aviso}/leitores', [AdminAvisoController::class, 'leitores']);
            Route::get('/avisos/{aviso}/exportar', [AdminAvisoController::class, 'exportar']);

            Route::get('/registros', [AdminRegistroController::class, 'index']);
            Route::get('/registros/exportar', [AdminRegistroController::class, 'exportar']);

            // Mala direta: comunicado em massa para um recorte da base.
            Route::get('/mala-direta', [AdminMalaDiretaController::class, 'index']);
            Route::get('/mala-direta/opcoes', [AdminMalaDiretaController::class, 'opcoes']);
            Route::post('/mala-direta/previa', [AdminMalaDiretaController::class, 'previa']);
            Route::post('/mala-direta/previa/exportar', [AdminMalaDiretaController::class, 'exportarPrevia']);
            // Disparo é caro e irreversível: limita a 10 malas por minuto.
            Route::post('/mala-direta', [AdminMalaDiretaController::class, 'store'])
                ->middleware('throttle:10,1');
            Route::get('/mala-direta/{mala}', [AdminMalaDiretaController::class, 'show']);
            Route::get('/mala-direta/{mala}/destinatarios', [AdminMalaDiretaController::class, 'destinatarios']);
            Route::get('/mala-direta/{mala}/exportar', [AdminMalaDiretaController::class, 'exportar']);
            Route::post('/mala-direta/{mala}/reenviar-falhas', [AdminMalaDiretaController::class, 'reenviarFalhas'])
                ->middleware('throttle:10,1');

            Route::get('/projetos-por-area', [AdminController::class, 'projetosPorArea']);
            Route::get('/projetos-por-localidade', [AdminController::class, 'projetosPorLocalidade']);
            Route::post('/admins', [AdminController::class, 'store']);
            Route::get('/admins', [AdminController::class, 'listarAdmins']);
            Route::put('/admins/{admin}', [AdminController::class, 'updateAdmin']);
            Route::patch('/admins/{admin}/status', [AdminController::class, 'statusAdmin']);

            // Parametrização do catálogo (áreas/subáreas)
            Route::get('/catalogo', [CatalogoAdminController::class, 'index']);
            Route::put('/areas/{area}', [CatalogoAdminController::class, 'updateArea']);
            Route::patch('/areas/{area}/correlacao', [CatalogoAdminController::class, 'correlacao']);
            Route::post('/areas/{area}/mesclar', [CatalogoAdminController::class, 'mergeArea']);
            Route::delete('/areas/{area}', [CatalogoAdminController::class, 'destroyArea']);
            Route::put('/subareas/{subarea}', [CatalogoAdminController::class, 'updateSubarea']);
            Route::post('/subareas/{subarea}/mesclar', [CatalogoAdminController::class, 'mergeSubarea']);
            Route::delete('/subareas/{subarea}', [CatalogoAdminController::class, 'destroySubarea']);

            // Parametrização das instituições de ensino (escolas)
            Route::get('/instituicoes', [InstituicaoAdminController::class, 'index']);
            Route::put('/instituicoes/{instituicao}', [InstituicaoAdminController::class, 'update']);
            Route::post('/instituicoes/{instituicao}/mesclar', [InstituicaoAdminController::class, 'merge']);
            Route::delete('/instituicoes/{instituicao}', [InstituicaoAdminController::class, 'destroy']);

            // Chat de suporte (inbox): conversas dos orientadores/avaliadores
            Route::get('/conversas', [ChatAdminController::class, 'index']);
            Route::get('/conversas-nao-vistas', [ChatAdminController::class, 'naoVistas']);
            Route::get('/conversas/{conversa}', [ChatAdminController::class, 'show']);
            Route::patch('/conversas/{conversa}/status', [ChatAdminController::class, 'atualizarStatus']);
            Route::post('/conversas/{conversa}/responder', [ChatAdminController::class, 'responder']);
        });
    });
});
