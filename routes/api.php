<?php

use App\Http\Controllers\Api\V1\AdminAvaliacaoController;
use App\Http\Controllers\Api\V1\AdminAvisoController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AdminFeedbackController;
use App\Http\Controllers\Api\V1\AdminInscricoesController;
use App\Http\Controllers\Api\V1\AdminMalaDiretaController;
use App\Http\Controllers\Api\V1\AdminModeloEmailController;
use App\Http\Controllers\Api\V1\AdminRascunhoController;
use App\Http\Controllers\Api\V1\AdminRegistroController;
use App\Http\Controllers\Api\V1\AlunoController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvaliadorAvaliacaoController;
use App\Http\Controllers\Api\V1\AvaliadorController;
use App\Http\Controllers\Api\V1\AvaliadorPerfilController;
use App\Http\Controllers\Api\V1\AvisoController;
use App\Http\Controllers\Api\V1\CadastroPendenteController;
use App\Http\Controllers\Api\V1\CatalogoAdminController;
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\ChatAdminController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\ComiteTransporteController;
use App\Http\Controllers\Api\V1\ContaTemporariaController;
use App\Http\Controllers\Api\V1\CoorientadorController;
use App\Http\Controllers\Api\V1\CredenciamentoController;
use App\Http\Controllers\Api\V1\DocumentoController;
use App\Http\Controllers\Api\V1\EdicaoController;
use App\Http\Controllers\Api\V1\EscopoAdminController;
use App\Http\Controllers\Api\V1\FeedbackController;
use App\Http\Controllers\Api\V1\InscricoesController;
use App\Http\Controllers\Api\V1\InstituicaoAdminController;
use App\Http\Controllers\Api\V1\IntegranteController;
use App\Http\Controllers\Api\V1\OrientadorAjusteController;
use App\Http\Controllers\Api\V1\OrientadorController;
use App\Http\Controllers\Api\V1\ParametrizacaoAbasController;
use App\Http\Controllers\Api\V1\ParametrizacaoCredenciamentoController;
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

    // Confirmação do e-mail do cadastro (orientador e avaliador): a conta só
    // nasce quando o código de 6 dígitos volta. O acesso é pelo token do
    // cadastro pendente — quem está aqui ainda não tem login.
    Route::prefix('cadastros/{cadastro:token}')->middleware('throttle:20,1')->group(function () {
        Route::get('/', [CadastroPendenteController::class, 'show']);
        Route::post('/confirmar', [CadastroPendenteController::class, 'confirmar']);
        Route::post('/reenviar', [CadastroPendenteController::class, 'reenviar']);
        Route::patch('/email', [CadastroPendenteController::class, 'trocarEmail']);
    });
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
        // Feedback: o balão que aparece ao entrar e o questionário em si.
        // Vale para todo usuário autenticado — o público de cada pedido é quem
        // decide quem vê.
        Route::get('/feedbacks/pendente', [FeedbackController::class, 'pendente']);
        Route::get('/feedbacks', [FeedbackController::class, 'index']);
        Route::post('/feedbacks/{feedback}/visto', [FeedbackController::class, 'visto']);
        Route::post('/feedbacks/{feedback}/dispensar', [FeedbackController::class, 'dispensar']);
        Route::post('/feedbacks/{feedback}/responder', [FeedbackController::class, 'responder'])
            ->middleware('throttle:20,1');

        Route::get('/avisos/ativo', [AvisoController::class, 'ativo']);
        Route::post('/avisos/{aviso}/visto', [AvisoController::class, 'visto']);
        Route::post('/avisos/{aviso}/fechar', [AvisoController::class, 'fechar']);

        // Prazo de inscrição: quem está logado precisa saber se ainda dá para
        // escrever (é o que explica os botões desabilitados na tela do orientador).
        Route::get('/inscricoes', [InscricoesController::class, 'show']);

        // Tudo que ESCREVE em projeto passa pelo prazo de submissão: depois da
        // data-limite a área do orientador fica só de leitura (GET passa sempre,
        // e o admin também).
        // Edição em escopo: qualquer usuário lista as edições e troca a sua —
        // trocar muda de uma vez os projetos, os prazos e os limites que ele vê.
        Route::get('edicoes', [EdicaoController::class, 'opcoes']);
        Route::put('edicoes/atual', [EdicaoController::class, 'trocar']);

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

        // Aba "Ajustes" do orientador: as sugestões dos avaliadores nos projetos
        // dele, respondidas durante o período de ajustes.
        Route::middleware('role:orientador')->prefix('ajustes')->group(function () {
            Route::get('/', [OrientadorAjusteController::class, 'index']);
            Route::get('/projetos/{projeto}', [OrientadorAjusteController::class, 'show']);
            Route::post('/projetos/{projeto}/decidir', [OrientadorAjusteController::class, 'decidir']);
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
            // Depois de enviada, só o parecer final ainda muda (com justificativa).
            Route::patch('/{avaliacao}/parecer', [AvaliadorAvaliacaoController::class, 'parecer']);
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

        // Administração (E8) — somente admin.
        //
        // Cada bloco pede a ABA correspondente no escopo do admin (Sprint 67).
        // Admin sem escopo atribuído na edição em curso tem acesso total, então
        // isto é transparente até alguém configurar os escopos. Onde a tela mora
        // em duas abas, o middleware aceita qualquer uma das duas.
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            // --- Aba "Projetos": painel, recortes e os rascunhos ---
            // O painel de números alimenta as duas abas: "Dashboards" o mostra
            // inteiro e "Projetos" fica com o recorte de projetos e localidades.
            Route::middleware('aba:projetos,dashboards')->group(function () {
                Route::get('/dashboard', [AdminController::class, 'dashboard']);
            });

            Route::middleware('aba:projetos')->group(function () {
                Route::get('/projetos-por-area', [AdminController::class, 'projetosPorArea']);
                Route::get('/projetos-por-localidade', [AdminController::class, 'projetosPorLocalidade']);
                // Projetos em rascunho: o admin termina e submete a inscrição que
                // ficou pela metade, mesmo com o prazo vencido (a edição em si
                // reaproveita as rotas de projeto/integrantes/documentos acima).
                Route::get('/projetos-rascunho', [AdminRascunhoController::class, 'index']);
            });

            // --- Aba "Avaliação online" ---
            Route::middleware('aba:avaliacao')->group(function () {
                Route::get('/avaliadores', [AdminController::class, 'avaliadores']);
                Route::get('/avaliacao/avaliadores', [AdminAvaliacaoController::class, 'avaliadores']);
                Route::get('/avaliacao/avaliadores/opcoes', [AdminAvaliacaoController::class, 'avaliadoresOpcoes']);
                Route::get('/avaliacao/avaliadores/exportar', [AdminAvaliacaoController::class, 'exportarAvaliadores']);
                Route::get('/avaliacao/orientadores/opcoes', [AdminAvaliacaoController::class, 'orientadoresOpcoes']);
                Route::get('/avaliacao/projetos', [AdminAvaliacaoController::class, 'projetos']);
                Route::get('/avaliacao/projetos/exportar', [AdminAvaliacaoController::class, 'exportarProjetos']);
                Route::get('/avaliacao/reclassificacoes', [AdminAvaliacaoController::class, 'reclassificacoes']);
                Route::post('/avaliacao/reclassificacoes/aplicar', [AdminAvaliacaoController::class, 'aplicarReclassificacoes']);
                Route::get('/avaliacao/ranking', [AdminAvaliacaoController::class, 'ranking']);
                Route::get('/avaliacao/ranking-avaliadores', [AdminAvaliacaoController::class, 'rankingAvaliadores']);
                Route::get('/avaliacao/lista-final/opcoes', [AdminAvaliacaoController::class, 'opcoesListaFinal']);
                Route::post('/avaliacao/lista-final', [AdminAvaliacaoController::class, 'gerarListaFinal']);
                // Listas finais oficiais registradas (a vigente define os finalistas).
                Route::get('/avaliacao/listas-finais', [AdminAvaliacaoController::class, 'listasFinais']);
                Route::get('/avaliacao/listas-finais/{lista}/arquivo', [AdminAvaliacaoController::class, 'baixarListaFinal']);
                Route::get('/avaliacao/listas-finais/{lista}', [AdminAvaliacaoController::class, 'mostrarListaFinal']);
                // Alterar a composição: justificativa obrigatória, versão nova e
                // registro em Registros → Lista final.
                Route::post('/avaliacao/listas-finais/{lista}/projetos', [AdminAvaliacaoController::class, 'adicionarNaListaFinal']);
                Route::delete('/avaliacao/listas-finais/{lista}/projetos/{projeto}', [AdminAvaliacaoController::class, 'removerDaListaFinal']);
                // Designações: a tabela com tudo que está na mão de cada avaliador.
                Route::get('/avaliacao/designacoes', [AdminAvaliacaoController::class, 'designacoes']);
                Route::post('/avaliacao/designacoes/retirar', [AdminAvaliacaoController::class, 'retirarDesignacoes']);
                Route::post('/avaliacao/projetos/{projeto}/designar', [AdminAvaliacaoController::class, 'designar']);
                // Correção manual da classificação/vídeo de um projeto submetido
                // (justificativa obrigatória; cada campo vira registro).
                Route::patch('/avaliacao/projetos/{projeto}', [AdminAvaliacaoController::class, 'corrigirProjeto']);
                Route::get('/avaliacao/distribuicao', [AdminAvaliacaoController::class, 'distribuicaoConfig']);
                Route::patch('/avaliacao/distribuicao', [AdminAvaliacaoController::class, 'definirRegrasDistribuicao']);
                Route::patch('/avaliacao/distribuicao/piso', [AdminAvaliacaoController::class, 'definirPisoFila']);
                Route::patch('/avaliacao/distribuicao/ao-cadastrar', [AdminAvaliacaoController::class, 'definirDistribuicaoAoCadastrar']);
                Route::post('/avaliacao/distribuir', [AdminAvaliacaoController::class, 'distribuir']);
                Route::post('/avaliacao/redistribuir', [AdminAvaliacaoController::class, 'redistribuir']);
                // As duas ações acima vão para a fila; a tela acompanha por aqui.
                Route::get('/avaliacao/distribuicoes/ultima', [AdminAvaliacaoController::class, 'ultimaDistribuicao']);
                Route::get('/avaliacao/distribuicoes/{distribuicao}', [AdminAvaliacaoController::class, 'progressoDistribuicao']);
                Route::patch('/avaliacao/avaliadores/{avaliador}/limite', [AdminAvaliacaoController::class, 'limitar']);
                Route::patch('/avaliacao/avaliadores/{avaliador}/demo', [AdminAvaliacaoController::class, 'demo']);
                Route::patch('/avaliacao/avaliadores/{avaliador}/comissao', [AdminAvaliacaoController::class, 'comissao']);
                Route::post('/avaliacao/avaliadores/{avaliador}/areas-extras', [AdminAvaliacaoController::class, 'adicionarAreaExtra']);
                Route::delete('/avaliacao/avaliadores/{avaliador}/areas-extras/{extra}', [AdminAvaliacaoController::class, 'removerAreaExtra']);
                Route::delete('/avaliacao/testes', [AdminAvaliacaoController::class, 'limparTestes']);
            });

            // --- Aba "Credenciamento": o balcão do evento ---
            Route::middleware('aba:credenciamento')->prefix('credenciamento')->group(function () {
                // Contas temporárias: quem atende o balcão sem ser da organização.
                Route::get('/contas', [ContaTemporariaController::class, 'index']);
                Route::post('/contas', [ContaTemporariaController::class, 'store']);
                Route::patch('/contas/{conta}/renovar', [ContaTemporariaController::class, 'renovar']);
                Route::patch('/contas/{conta}/desativar', [ContaTemporariaController::class, 'desativar']);

                Route::get('/config', [CredenciamentoController::class, 'config']);
                Route::get('/finalistas', [CredenciamentoController::class, 'index']);
                Route::get('/projetos/{projeto}', [CredenciamentoController::class, 'show']);
                Route::post('/projetos/{projeto}', [CredenciamentoController::class, 'store']);
                Route::post('/projetos/{projeto}/cancelar', [CredenciamentoController::class, 'cancelar']);
            });

            // --- Aba "Comitê especial": transporte e mapa em tempo real ---
            Route::middleware('aba:comite')->prefix('comite')->group(function () {
                Route::get('/localizacao', [ComiteTransporteController::class, 'minha']);
                Route::post('/localizacao', [ComiteTransporteController::class, 'iniciar']);
                Route::patch('/localizacao', [ComiteTransporteController::class, 'prorrogar']);
                Route::delete('/localizacao', [ComiteTransporteController::class, 'encerrar']);
                // Uma posição a cada 5s: o teto acomoda a sessão inteira com folga.
                Route::post('/localizacao/ponto', [ComiteTransporteController::class, 'ponto'])
                    ->middleware('throttle:60,1');
                Route::get('/mapa', [ComiteTransporteController::class, 'mapa']);
                Route::get('/mapa/{localizacao}', [ComiteTransporteController::class, 'detalhe']);
            });

            // --- Parametrização (as datas do período de avaliação moram nas
            //     duas abas: quem cuida da avaliação também as ajusta) ---
            Route::middleware('aba:parametrizacao,avaliacao')->group(function () {
                Route::get('/avaliacao/config', [AdminAvaliacaoController::class, 'config']);
                Route::patch('/avaliacao/config', [AdminAvaliacaoController::class, 'definirLiberacao']);
                Route::patch('/avaliacao/encerramento', [AdminAvaliacaoController::class, 'definirEncerramento']);
                Route::patch('/avaliacao/minimos', [AdminAvaliacaoController::class, 'definirMinimos']);
                Route::patch('/avaliacao/ajustes', [AdminAvaliacaoController::class, 'definirAjustes']);
            });

            Route::middleware('aba:parametrizacao')->group(function () {
                // Aba "Inscrições": prazo de submissão dos projetos.
                Route::get('/inscricoes', [AdminInscricoesController::class, 'show']);
                Route::patch('/inscricoes/prazo', [AdminInscricoesController::class, 'definirPrazo']);
                Route::patch('/inscricoes/inicio', [AdminInscricoesController::class, 'definirInicio']);

                // Parametrização → Edições: criar a edição do ano, escolher a
                // padrão (a que vale para quem não trocou) e excluir uma vazia.
                Route::get('/edicoes', [EdicaoController::class, 'index']);
                Route::post('/edicoes', [EdicaoController::class, 'store']);
                Route::put('/edicoes/{edicao}', [EdicaoController::class, 'update']);
                Route::patch('/edicoes/{edicao}/padrao', [EdicaoController::class, 'padrao']);
                Route::delete('/edicoes/{edicao}', [EdicaoController::class, 'destroy']);

                // Parametrização → Escopos de admin: quais abas cada perfil abre.
                Route::get('/escopos', [EscopoAdminController::class, 'index']);
                Route::post('/escopos', [EscopoAdminController::class, 'store']);
                Route::put('/escopos/{escopo}', [EscopoAdminController::class, 'update']);
                Route::delete('/escopos/{escopo}', [EscopoAdminController::class, 'destroy']);

                // Parametrização → Ordem do menu: em que ordem as abas do admin
                // aparecem no menu lateral e na Home (por edição).
                Route::get('/abas', [ParametrizacaoAbasController::class, 'show']);
                Route::put('/abas', [ParametrizacaoAbasController::class, 'definir']);
                Route::delete('/abas', [ParametrizacaoAbasController::class, 'restaurar']);

                // Parametrização → Credenciamento: janela do evento e a lista de
                // documentos exigida de cada papel no balcão.
                Route::get('/credenciamento', [ParametrizacaoCredenciamentoController::class, 'show']);
                Route::patch('/credenciamento/janela', [ParametrizacaoCredenciamentoController::class, 'definirJanela']);
                Route::patch('/credenciamento/itens', [ParametrizacaoCredenciamentoController::class, 'definirItens']);
                Route::post('/credenciamento/documentos', [ParametrizacaoCredenciamentoController::class, 'criarDocumento']);
                Route::put('/credenciamento/documentos/{documento}', [ParametrizacaoCredenciamentoController::class, 'atualizarDocumento']);
                Route::delete('/credenciamento/documentos/{documento}', [ParametrizacaoCredenciamentoController::class, 'excluirDocumento']);

                // Parametrização do catálogo (áreas/subáreas)
                Route::get('/catalogo', [CatalogoAdminController::class, 'index']);
                Route::put('/areas/{area}', [CatalogoAdminController::class, 'updateArea']);
                Route::patch('/areas/{area}/correlacao', [CatalogoAdminController::class, 'correlacao']);
                Route::patch('/areas/{area}/sigla', [CatalogoAdminController::class, 'sigla']);
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
            });

            // --- Aba "Comunicação": avisos, modelos de e-mail e mala direta ---
            Route::middleware('aba:comunicacao')->group(function () {
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

                // Comunicação → Feedback: o questionário e os seus resultados.
                Route::get('/feedbacks/opcoes', [AdminFeedbackController::class, 'opcoes']);
                Route::get('/feedbacks', [AdminFeedbackController::class, 'index']);
                Route::post('/feedbacks', [AdminFeedbackController::class, 'store']);
                Route::get('/feedbacks/{feedback}', [AdminFeedbackController::class, 'show']);
                Route::get('/feedbacks/{feedback}/destinatarios', [AdminFeedbackController::class, 'destinatarios']);
                Route::get('/feedbacks/{feedback}/exportar', [AdminFeedbackController::class, 'exportar']);
                Route::post('/feedbacks/{feedback}/reenviar-falhas', [AdminFeedbackController::class, 'reenviarFalhas']);
                Route::post('/feedbacks/{feedback}/encerrar', [AdminFeedbackController::class, 'encerrar']);

                // Comunicação → Modelos de e-mail: o texto dos e-mails automáticos.
                Route::get('/modelos-email', [AdminModeloEmailController::class, 'index']);
                Route::get('/modelos-email/{modelo}', [AdminModeloEmailController::class, 'show']);
                Route::put('/modelos-email/{modelo}', [AdminModeloEmailController::class, 'update']);
                Route::delete('/modelos-email/{modelo}', [AdminModeloEmailController::class, 'restaurar']);

                // Mala direta: comunicado em massa para um recorte da base.
                Route::get('/mala-direta', [AdminMalaDiretaController::class, 'index']);
                Route::get('/mala-direta/opcoes', [AdminMalaDiretaController::class, 'opcoes']);
                Route::post('/mala-direta/previa', [AdminMalaDiretaController::class, 'previa']);
                Route::post('/mala-direta/previa/exportar', [AdminMalaDiretaController::class, 'exportarPrevia']);

                // Imagens do corpo e anexos da mensagem. Sobem antes do disparo (a
                // mala ainda não existe) e ficam num disco privado.
                Route::post('/mala-direta/arquivos', [AdminMalaDiretaController::class, 'subirArquivo'])
                    ->middleware('throttle:60,1');
                Route::get('/mala-direta/arquivos/{arquivo}', [AdminMalaDiretaController::class, 'baixarArquivo']);
                Route::delete('/mala-direta/arquivos/{arquivo}', [AdminMalaDiretaController::class, 'removerArquivo']);
                // Disparo é caro e irreversível: limita a 10 malas por minuto.
                Route::post('/mala-direta', [AdminMalaDiretaController::class, 'store'])
                    ->middleware('throttle:10,1');
                Route::get('/mala-direta/{mala}', [AdminMalaDiretaController::class, 'show']);
                Route::get('/mala-direta/{mala}/destinatarios', [AdminMalaDiretaController::class, 'destinatarios']);
                Route::get('/mala-direta/{mala}/exportar', [AdminMalaDiretaController::class, 'exportar']);
                Route::post('/mala-direta/{mala}/reenviar-falhas', [AdminMalaDiretaController::class, 'reenviarFalhas'])
                    ->middleware('throttle:10,1');
            });

            // --- Aba "Registros": a trilha de auditoria ---
            Route::middleware('aba:registros')->group(function () {
                Route::get('/registros', [AdminRegistroController::class, 'index']);
                Route::get('/registros/exportar', [AdminRegistroController::class, 'exportar']);
            });

            // --- Aba "Administradores": contas e escopos de cada admin ---
            Route::middleware('aba:administradores')->group(function () {
                Route::post('/admins', [AdminController::class, 'store']);
                Route::get('/admins', [AdminController::class, 'listarAdmins']);
                Route::put('/admins/{admin}', [AdminController::class, 'updateAdmin']);
                Route::patch('/admins/{admin}/status', [AdminController::class, 'statusAdmin']);
                Route::patch('/admins/{admin}/demo', [AdminController::class, 'demoAdmin']);
                Route::put('/admins/{admin}/escopos', [EscopoAdminController::class, 'atribuir']);
                // Contas demo: o mesmo interruptor, para orientadores e avaliadores
                // (o do admin é a linha dele na lista acima).
                Route::get('/contas-demo', [AdminController::class, 'contasDemo']);
                Route::patch('/contas-demo/{usuario}', [AdminController::class, 'demoParticipante']);
            });

            // --- Aba "Suporte": inbox do chat ---
            Route::middleware('aba:suporte')->group(function () {
                Route::get('/conversas', [ChatAdminController::class, 'index']);
                Route::get('/conversas-nao-vistas', [ChatAdminController::class, 'naoVistas']);
                Route::get('/conversas/{conversa}', [ChatAdminController::class, 'show']);
                Route::patch('/conversas/{conversa}/status', [ChatAdminController::class, 'atualizarStatus']);
                Route::post('/conversas/{conversa}/responder', [ChatAdminController::class, 'responder']);
            });
        });
    });
});
