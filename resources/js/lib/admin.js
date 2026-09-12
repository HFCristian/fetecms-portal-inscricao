import http from './http.js';

// Dispara o download de um blob (os CSVs do painel) com o nome que o servidor mandou.
function baixarBlob(blob, nome) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = nome;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}


export const getDashboard = () => http.get('/admin/dashboard').then((r) => r.data.data);

export const getAvaliadores = () => http.get('/admin/avaliadores').then((r) => r.data.data);

// Parametrização → Inscrições: a janela de inscrição (abertura + prazo).
// Estas devolvem { data, meta } — o CampoDataCard mostra a mensagem do backend.
export const getInscricoesConfig = () => http.get('/admin/inscricoes').then((r) => r.data.data);
export const definirPrazoInscricoes = (prazo) =>
    http.patch('/admin/inscricoes/prazo', { prazo }).then((r) => r.data);
export const definirInicioInscricoes = (inicio) =>
    http.patch('/admin/inscricoes/inicio', { inicio }).then((r) => r.data);

// Avisos na tela dos orientadores (um ativo por vez).
export const getAvisoOpcoes = () => http.get('/admin/avisos/opcoes').then((r) => r.data.data);
// Avisos no ar agora: podem ser vários, um por público.
export const getAvisosVigentes = () => http.get('/admin/avisos/ativo').then((r) => r.data.data);
export const previaAviso = (payload) => http.post('/admin/avisos/previa', payload).then((r) => r.data.data);
export const publicarAviso = (payload) => http.post('/admin/avisos', payload).then((r) => r.data);
export const encerrarAviso = (id) => http.post(`/admin/avisos/${id}/encerrar`).then((r) => r.data);

// Histórico e relatório de leitura dos avisos.
const avisoParams = ({ situacao, q, page } = {}) => ({
    params: {
        ...(situacao ? { situacao } : {}),
        ...(q ? { q } : {}),
        page: page ?? 1,
    },
});

export const getAvisos = (page = 1) => http.get('/admin/avisos', { params: { page } }).then((r) => r.data);
export const getAviso = (id) => http.get(`/admin/avisos/${id}`).then((r) => r.data);
export const getAvisoLeitores = (id, filtros) =>
    http.get(`/admin/avisos/${id}/leitores`, avisoParams(filtros)).then((r) => r.data);

/** Baixa o CSV do mesmo recorte que está na tela (a sessão vai no cookie). */
export async function exportarAvisoCsv(id, filtros) {
    const r = await http.get(`/admin/avisos/${id}/exportar`, {
        ...avisoParams(filtros),
        responseType: 'blob',
    });
    const nome = /filename="([^"]+)"/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? `aviso-${id}.csv`;
    baixarBlob(r.data, nome);
}

// Avaliação online (E7): configuração de liberação, avaliadores e projetos por área.
export const getAvaliacaoConfig = () => http.get('/admin/avaliacao/config').then((r) => r.data.data);
export const definirLiberacaoAvaliacao = (liberadaEm) =>
    http.patch('/admin/avaliacao/config', { liberada_em: liberadaEm }).then((r) => r.data);
export const definirEncerramentoAvaliacao = (encerradaEm) =>
    http.patch('/admin/avaliacao/encerramento', { encerrada_em: encerradaEm }).then((r) => r.data);
// Limites do edital: cada card manda só o seu bloco. O máximo por avaliador
// aceita null — quer dizer "sem teto".
export const definirLimitesAvaliador = (min, max) =>
    http.patch('/admin/avaliacao/minimos', { min_por_avaliador: min, max_por_avaliador: max }).then((r) => r.data);
export const definirLimitesProjeto = (min, max, categorias) =>
    http.patch('/admin/avaliacao/minimos', {
        min_por_projeto: min, max_por_projeto: max, categorias,
    }).then((r) => r.data);
// Tabela de avaliadores: { q, area_id, ordenar, direcao, page }. A resposta traz
// { data, meta } (paginação, áreas para o filtro e a ordenação em vigor).
const avaliadorParams = ({ q, areaId, situacao, ordenar, direcao, page } = {}) => ({
    params: {
        ...(q ? { q } : {}),
        ...(areaId ? { area_id: areaId } : {}),
        ...(situacao ? { situacao } : {}),
        ...(ordenar ? { ordenar } : {}),
        ...(direcao ? { direcao } : {}),
        page: page ?? 1,
    },
});

export const getAvaliacaoAvaliadores = (filtros) =>
    http.get('/admin/avaliacao/avaliadores', avaliadorParams(filtros)).then((r) => r.data);

/** Lista enxuta (id, nome, área) para os seletores de designação. */
export const getOpcoesAvaliadores = (somenteComissao = false) =>
    http.get('/admin/avaliacao/avaliadores/opcoes', { params: somenteComissao ? { comissao: 1 } : {} })
        .then((r) => r.data.data);

/** Baixa o CSV da tabela de avaliadores no recorte atual. */
export async function exportarAvaliadoresCsv(filtros) {
    const r = await http.get('/admin/avaliacao/avaliadores/exportar', {
        ...avaliadorParams(filtros),
        responseType: 'blob',
    });
    const nome = /filename="([^"]+)"/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? 'avaliadores.csv';
    baixarBlob(r.data, nome);
}
export const definirLimiteAvaliador = (avaliadorId, limite) =>
    http.patch(`/admin/avaliacao/avaliadores/${avaliadorId}/limite`, { limite }).then((r) => r.data);
export const definirDemoAvaliador = (avaliadorId, isDemo) =>
    http.patch(`/admin/avaliacao/avaliadores/${avaliadorId}/demo`, { is_demo: isDemo }).then((r) => r.data);
export const definirComissaoAvaliador = (avaliadorId, comissao) =>
    http.patch(`/admin/avaliacao/avaliadores/${avaliadorId}/comissao`, { comissao_especial: comissao }).then((r) => r.data);
// Áreas extras: só o admin amplia o alcance de um avaliador. As duas rotas
// devolvem a linha atualizada do avaliador.
export const adicionarAreaExtra = (avaliadorId, areaId, subareaId) =>
    http.post(`/admin/avaliacao/avaliadores/${avaliadorId}/areas-extras`, { area_id: areaId, subarea_id: subareaId || null }).then((r) => r.data);
export const removerAreaExtra = (avaliadorId, extraId) =>
    http.delete(`/admin/avaliacao/avaliadores/${avaliadorId}/areas-extras/${extraId}`).then((r) => r.data);
export const limparDadosDeTeste = () => http.delete('/admin/avaliacao/testes').then((r) => r.data);
// Tabela de projetos submetidos: { q, areaId, categoria, ordenar, direcao, page }.
const projetoParams = ({ q, areaId, categoria, ordenar, direcao, page } = {}) => ({
    params: {
        ...(q ? { q } : {}),
        ...(areaId ? { area_id: areaId } : {}),
        ...(categoria ? { categoria } : {}),
        ...(ordenar ? { ordenar } : {}),
        ...(direcao ? { direcao } : {}),
        page: page ?? 1,
    },
});

export const getAvaliacaoProjetos = (filtros) =>
    http.get('/admin/avaliacao/projetos', projetoParams(filtros)).then((r) => r.data);

// Tabela de designações: { q, areaId, categoria, situacao, avaliadorId, ordenar, direcao, page }.
export const getDesignacoes = ({ q, areaId, categoria, situacao, avaliadorId, ordenar, direcao, page } = {}) =>
    http.get('/admin/avaliacao/designacoes', {
        params: {
            ...(q ? { q } : {}),
            ...(areaId ? { area_id: areaId } : {}),
            ...(categoria ? { categoria } : {}),
            ...(situacao ? { situacao } : {}),
            ...(avaliadorId ? { avaliador_id: avaliadorId } : {}),
            ...(ordenar ? { ordenar } : {}),
            ...(direcao ? { direcao } : {}),
            page: page ?? 1,
        },
    }).then((r) => r.data);

/** Orientadores para a troca de dono do projeto (busca no servidor, até 20). */
export const buscarOrientadores = (q) =>
    http.get('/admin/avaliacao/orientadores/opcoes', { params: q ? { q } : {} }).then((r) => r.data.data);

/** Retira as designações marcadas; cada projeto vai para outro avaliador na hora. */
export const retirarDesignacoes = (avaliacaoIds) =>
    http.post('/admin/avaliacao/designacoes/retirar', { avaliacao_ids: avaliacaoIds }).then((r) => r.data);

/** Baixa o CSV da tabela de projetos no recorte atual. */
export async function exportarProjetosAvaliacaoCsv(filtros) {
    const r = await http.get('/admin/avaliacao/projetos/exportar', {
        ...projetoParams(filtros),
        responseType: 'blob',
    });
    const nome = /filename="([^"]+)"/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? 'projetos-submetidos.csv';
    baixarBlob(r.data, nome);
}
export const designarProjeto = (projetoId, payload) =>
    http.post(`/admin/avaliacao/projetos/${projetoId}/designar`, payload).then((r) => r.data);
/**
 * Enfileira uma rodada de distribuição. Responde 202 com o registro da rodada —
 * o trabalho acontece na fila, e a tela acompanha por `getProgressoDistribuicao`.
 */
export const distribuirAvaliacoes = () => http.post('/admin/avaliacao/distribuir').then((r) => r.data);

export const getProgressoDistribuicao = (id) =>
    http.get(`/admin/avaliacao/distribuicoes/${id}`).then((r) => r.data.data);

/** A rodada mais recente da edição (null quando nunca houve uma). */
export const getUltimaDistribuicao = () =>
    http.get('/admin/avaliacao/distribuicoes/ultima').then((r) => r.data.data);

// Algoritmo de distribuição: a regra de cada categoria (quem entra e em que
// faixa de avaliações concluídas). O formulário salva as três de uma vez.
export const getDistribuicaoConfig = () => http.get('/admin/avaliacao/distribuicao').then((r) => r.data.data);
export const definirRegrasDistribuicao = (regras) =>
    http.patch('/admin/avaliacao/distribuicao', { regras }).then((r) => r.data);
// Toggle: avaliador recém-cadastrado já sai com projetos na fila.
export const definirDistribuicaoAoCadastrar = (aoCadastrar) =>
    http.patch('/admin/avaliacao/distribuicao/ao-cadastrar', { ao_cadastrar: aoCadastrar }).then((r) => r.data);
// Rodízio: devolve ao bolo o que ainda não foi aberto e sorteia outros.
export const redistribuirAvaliacoes = () => http.post('/admin/avaliacao/redistribuir').then((r) => r.data);

/**
 * Quantos avaliadores a distribuição designa por projeto (o número geral da
 * edição). `null` volta ao comportamento histórico: o alvo é o mínimo por
 * projeto de cada categoria.
 */
export const definirDesignacoesPorProjeto = (designacoes) =>
    http.patch('/admin/avaliacao/distribuicao/designacoes', {
        designacoes_por_projeto: designacoes ?? null,
    }).then((r) => r.data);

/**
 * Piso da fila do avaliador: a rede de segurança das regras por categoria.
 * `null` desliga o piso — aí a regra manda sozinha.
 */
export const definirPisoFila = (piso) =>
    http.patch('/admin/avaliacao/distribuicao/piso', { piso_fila: piso ?? null }).then((r) => r.data);

/**
 * Modo de distribuição da edição: `total` (o admin distribui em massa) ou
 * `atividade` (a fila nasce no login do avaliador e volta ao bolo no fim da
 * sessão). A troca não mexe no que já está designado.
 */
export const definirModoDistribuicao = (modo) =>
    http.patch('/admin/avaliacao/distribuicao/modo', { modo }).then((r) => r.data);

/**
 * Os dois prazos do ciclo de vida de uma designação: horas sem atividade que
 * encerram a sessão do avaliador (modo por atividade) e dias que uma avaliação
 * pode ficar aberta antes de o projeto voltar para a pilha (os dois modos).
 * Dias em branco desliga a regra; horas em branco volta ao padrão.
 */
export const definirPrazosSessao = (horas, dias) =>
    http.patch('/admin/avaliacao/distribuicao/prazos', {
        horas_sessao: horas ?? null,
        dias_avaliacao_aberta: dias ?? null,
    }).then((r) => r.data);

// Lista final da feira: o que dá para pedir e o TXT do recorte escolhido.
export const getOpcoesListaFinal = () => http.get('/admin/avaliacao/lista-final/opcoes').then((r) => r.data.data);

export async function baixarListaFinal(cotas) {
    const r = await http.post('/admin/avaliacao/lista-final', cotas, { responseType: 'blob' });
    const nome = /filename="([^"]+)"/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? 'lista-final.txt';
    baixarBlob(r.data, nome);
}

/** Listas finais oficiais registradas na edição em curso. */
export const getListasFinais = () =>
    http.get('/admin/avaliacao/listas-finais').then((r) => r.data.data);

/** Baixa o TXT de uma lista oficial na composição atual dela. */
export async function baixarListaOficial(id) {
    const r = await http.get(`/admin/avaliacao/listas-finais/${id}/arquivo`, { responseType: 'blob' });
    const nome = /filename="([^"]+)"/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? 'lista-final.txt';
    baixarBlob(r.data, nome);
}

/** Uma lista oficial aberta para edição: composição atual + candidatos. */
export const getListaFinal = (id) =>
    http.get(`/admin/avaliacao/listas-finais/${id}`).then((r) => r.data.data);

/** Inclui um projeto na lista oficial (justificativa obrigatória). */
export const adicionarNaListaFinal = (id, projetoId, justificativa) =>
    http.post(`/admin/avaliacao/listas-finais/${id}/projetos`, { projeto_id: projetoId, justificativa })
        .then((r) => r.data.data);

/** Retira um projeto da lista oficial (justificativa obrigatória). */
export const removerDaListaFinal = (id, projetoId, justificativa) =>
    http.delete(`/admin/avaliacao/listas-finais/${id}/projetos/${projetoId}`, { data: { justificativa } })
        .then((r) => r.data.data);

// Projetos com sugestão de reclassificação. `filtros`: { area_id, q, de, ate }.
export const getReclassificacoes = (filtros = {}) =>
    http.get('/admin/avaliacao/reclassificacoes', { params: limpar(filtros) }).then((r) => r.data.data);

// Aceita sugestões de reclassificação em lote.
// `itens`: [{ projeto_id, area_id?, subarea_id? }] — ao menos um dos dois por item.
export const aplicarReclassificacoes = (itens) =>
    http.post('/admin/avaliacao/reclassificacoes/aplicar', { itens }).then((r) => r.data);

// Ranking dos projetos avaliados (média das notas finais).
// `filtros`: { area_id, categoria }. Devolve { data, meta } — meta traz as categorias.
export const getRankingAvaliacao = (filtros = {}) =>
    http.get('/admin/avaliacao/ranking', { params: limpar(filtros) }).then((r) => r.data);

// Ranking dos avaliadores que mais concluíram avaliações.
export const getRankingAvaliadores = () =>
    http.get('/admin/avaliacao/ranking-avaliadores').then((r) => r.data.data);

/** Remove chaves vazias para não mandar `?q=&area_id=` na query. */
function limpar(filtros) {
    return Object.fromEntries(
        Object.entries(filtros).filter(([, v]) => v !== '' && v !== null && v !== undefined),
    );
}

export const getProjetosPorArea = () => http.get('/admin/projetos-por-area').then((r) => r.data.data);

/** Projetos em rascunho (o admin termina e submete depois do prazo). */
export const getProjetosRascunho = (filtros = {}) =>
    http.get('/admin/projetos-rascunho', { params: filtros }).then((r) => r.data);

export const getProjetosPorLocalidade = () => http.get('/admin/projetos-por-localidade').then((r) => r.data.data);

export const criarAdmin = (payload) => http.post('/admin/admins', payload).then((r) => r.data.data);

// Gestão de administradores (listar, editar nome/email, ativar/desativar).
/**
 * Administradores + o escopo de cada um na edição em curso.
 * Devolve o payload inteiro: `{ data, meta: { escopos, escopo_por_admin } }`.
 */
export const getAdmins = () => http.get('/admin/admins').then((r) => r.data);
export const atualizarAdmin = (id, payload) => http.put(`/admin/admins/${id}`, payload).then((r) => r.data.data);
export const definirStatusAdmin = (id, isActive) =>
    http.patch(`/admin/admins/${id}/status`, { is_active: isActive }).then((r) => r.data.data);

// Parametrização do catálogo (áreas/subáreas). Toda mutação devolve a árvore atualizada.
// A árvore vem em `data` e os grupos de áreas correlatas em `meta.grupos` — as
// mutações devolvem só a árvore, então os grupos são lidos uma vez, na carga.
export const getCatalogo = () => http.get('/admin/catalogo')
    .then((r) => ({ areas: r.data.data, grupos: r.data.meta?.grupos ?? [] }));
export const renomearArea = (id, nome) => http.put(`/admin/areas/${id}`, { nome }).then((r) => r.data.data);
export const mesclarArea = (id, destinoId) => http.post(`/admin/areas/${id}/mesclar`, { destino_id: destinoId }).then((r) => r.data.data);
export const excluirArea = (id) => http.delete(`/admin/areas/${id}`).then((r) => r.data.data);
export const definirCorrelacaoArea = (id, grupo) => http.patch(`/admin/areas/${id}/correlacao`, { grupo_correlato: grupo || null }).then((r) => r.data.data);
// Sigla de três letras da área — o "AGR" de FET.AGR-001 na lista final.
export const definirSiglaArea = (id, sigla) => http.patch(`/admin/areas/${id}/sigla`, { sigla: sigla || null }).then((r) => r.data.data);
export const renomearSubarea = (id, nome) => http.put(`/admin/subareas/${id}`, { nome }).then((r) => r.data.data);
export const mesclarSubarea = (id, destinoId) => http.post(`/admin/subareas/${id}/mesclar`, { destino_id: destinoId }).then((r) => r.data.data);
export const excluirSubarea = (id) => http.delete(`/admin/subareas/${id}`).then((r) => r.data.data);

// Parametrização de instituições (escolas). Toda mutação devolve a lista filtrada pelo
// termo de busca atual (passado em query) para a tela recarregar sem segunda requisição.
// opts: { search, ordenar: 'nome'|'criacao', page }. Toda chamada devolve { data, meta }
// (meta com pagina_atual/ultima_pagina/total/por_pagina) para paginar sem 2ª requisição.
const instParams = ({ search, ordenar, page } = {}) => ({
    params: { ...(search ? { search } : {}), ordenar: ordenar ?? 'nome', page: page ?? 1 },
});
export const getInstituicoesAdmin = (opts) => http.get('/admin/instituicoes', instParams(opts)).then((r) => r.data);

export const renomearInstituicao = (id, nome, opts) => http.put(`/admin/instituicoes/${id}`, { nome }, instParams(opts)).then((r) => r.data);
export const mesclarInstituicao = (id, destinoId, opts) => http.post(`/admin/instituicoes/${id}/mesclar`, { destino_id: destinoId }, instParams(opts)).then((r) => r.data);
export const excluirInstituicao = (id, opts) => http.delete(`/admin/instituicoes/${id}`, instParams(opts)).then((r) => r.data);

// Trilha de registros, em duas seções: `secao: 'inscricoes'` (submissões,
// cancelamentos, exclusões e trocas de e-mail) e `secao: 'avaliacao'` (mudanças de
// parâmetro do período). `filtros`: { secao, tipos: string[], de, ate, busca, page }.
// A resposta traz { data, meta } (meta com paginação, totais por tipo e os tipos
// disponíveis na seção).
const registroParams = ({ secao, tipos, de, ate, busca, page } = {}) => ({
    params: {
        ...(secao ? { secao } : {}),
        ...(tipos?.length ? { tipos: tipos.join(',') } : {}),
        ...(de ? { de } : {}),
        ...(ate ? { ate } : {}),
        ...(busca ? { busca } : {}),
        page: page ?? 1,
    },
});

export const getRegistros = (filtros) =>
    http.get('/admin/registros', registroParams(filtros)).then((r) => r.data);

/** Baixa o CSV do mesmo recorte que está na tela (a sessão vai no cookie). */
export async function exportarRegistrosCsv(filtros) {
    const r = await http.get('/admin/registros/exportar', {
        ...registroParams(filtros),
        responseType: 'blob',
    });
    const nome = /filename="([^"]+)"/.exec(r.headers['content-disposition'] ?? '')?.[1]
        ?? 'registros.csv';
    baixarBlob(r.data, nome);
}

/**
 * Início/fim do período de ajustes do orientador (aba "Ajustes").
 *
 * Devolvem o envelope inteiro (`{ data, meta }`), como os demais campos de data:
 * é o que o `CampoDataCard` espera para repassar o config novo e mostrar a
 * mensagem do backend. Desembrulhar aqui deixava o card sem config.
 */
export const definirInicioAjustes = (data) =>
    http.patch('/admin/avaliacao/ajustes', { ponta: 'de', data }).then((r) => r.data);

export const definirFimAjustes = (data) =>
    http.patch('/admin/avaliacao/ajustes', { ponta: 'ate', data }).then((r) => r.data);

/**
 * Correção manual de um projeto submetido (categoria, área, subárea e vídeo).
 * A justificativa é obrigatória — ela vai para a trilha de registros.
 */
export const corrigirProjeto = (projetoId, dados) =>
    http.patch(`/admin/avaliacao/projetos/${projetoId}`, dados).then((r) => r.data);

// Comunicação → Modelos de e-mail: o texto dos e-mails automáticos do portal.
// Sem customização salva, a API devolve o texto de fábrica.
export const getModelosEmail = () => http.get('/admin/modelos-email').then((r) => r.data.data);

export const salvarModeloEmail = (chave, dados) =>
    http.put(`/admin/modelos-email/${chave}`, dados).then((r) => r.data.data);

/** Volta o modelo ao texto padrão (apaga a customização). */
export const restaurarModeloEmail = (chave) =>
    http.delete(`/admin/modelos-email/${chave}`).then((r) => r.data.data);

// --- Parametrização → Escopos de admin (Sprint 67) ---

export const getEscopos = () => http.get('/admin/escopos').then((r) => r.data.data);

export const criarEscopo = (payload) => http.post('/admin/escopos', payload).then((r) => r.data.data);

export const atualizarEscopo = (id, payload) =>
    http.put(`/admin/escopos/${id}`, payload).then((r) => r.data.data);

export const excluirEscopo = (id) => http.delete(`/admin/escopos/${id}`).then((r) => r.data.data);

/**
 * Liga/desliga o **modo demo** de um administrador: com ele, as telas que
 * dependem de data (credenciamento, ajustes) oferecem o "modo de teste".
 */
export const definirDemoAdmin = (adminId, demo) =>
    http.patch(`/admin/admins/${adminId}/demo`, { is_demo: demo }).then((r) => r.data);

/**
 * Define o conjunto de escopos de um admin na edição em curso — o acesso dele é
 * a união das abas de todos. Lista vazia devolve o acesso total.
 */
export const definirEscoposAdmin = (adminId, escopoIds) =>
    http.put(`/admin/admins/${adminId}/escopos`, { escopo_ids: escopoIds ?? [] }).then((r) => r.data.data);

/**
 * Ordem das abas do menu (Parametrização → Ordem do menu). É da **edição**:
 * trocar de edição no seletor do topo troca também o menu.
 */
export const getOrdemAbas = () => http.get('/admin/abas').then((r) => r.data.data);

/** A lista inteira, na ordem em que a tela a deixou. */
export const salvarOrdemAbas = (ordem) => http.put('/admin/abas', { ordem }).then((r) => r.data);

/** Volta à ordem original do portal. */
export const restaurarOrdemAbas = () => http.delete('/admin/abas').then((r) => r.data);

/**
 * Contas demo (Administradores → Contas demo): orientadores e avaliadores
 * usados para ensaiar as telas presas a data.
 *
 * **Sem `busca` a API devolve só quem já está marcado** — é a lista das contas
 * de treinamento existentes. Com busca, procura em toda a base de participantes.
 */
export const getContasDemo = (filtros = {}) =>
    http.get('/admin/contas-demo', { params: filtros }).then((r) => r.data);

/** O mesmo interruptor do admin, para um orientador ou avaliador. */
export const definirDemoParticipante = (userId, demo) =>
    http.patch(`/admin/contas-demo/${userId}`, { is_demo: demo }).then((r) => r.data);

/**
 * Designação em massa (Avaliação online → Designações): as duas listas do
 * diálogo, buscadas no servidor porque a base é grande demais para viajar
 * inteira a cada abertura.
 */
export const getOpcoesDesignacao = (params = {}) =>
    http.get('/admin/avaliacao/designacoes/opcoes', { params }).then((r) => r.data.data);

/** N projetos × N avaliadores de uma vez. */
export const designarEmMassa = (projetoIds, avaliadorIds) =>
    http.post('/admin/avaliacao/designacoes/designar', {
        projeto_ids: projetoIds,
        avaliador_ids: avaliadorIds,
    }).then((r) => r.data);
