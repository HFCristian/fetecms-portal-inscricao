import http from './http.js';

/**
 * Aba Credenciamento (o balcão do evento) e a parametrização dela.
 *
 * `teste` só tem efeito para o admin demo: liga o modo de teste, que ignora a
 * janela do evento para ele conhecer a tela antes.
 */
const comTeste = (params = {}, teste = false) => (teste ? { ...params, teste: 1 } : params);

export const getCredenciamentoConfig = (teste = false) =>
    http.get('/admin/credenciamento/config', { params: comTeste({}, teste) }).then((r) => r.data.data);

/** Finalistas com a situação de cada um. `filtros`: { busca, area_id, categoria, situacao, page }. */
export const getFinalistas = (filtros = {}, teste = false) =>
    http.get('/admin/credenciamento/finalistas', { params: comTeste(filtros, teste) }).then((r) => r.data);

/** A ficha de um finalista: pessoas × documentos exigidos. */
export const getFichaCredenciamento = (projetoId, teste = false) =>
    http.get(`/admin/credenciamento/projetos/${projetoId}`, { params: comTeste({}, teste) })
        .then((r) => r.data.data);

/** Conclui o credenciamento com a conferência dos documentos. */
export const credenciarProjeto = (projetoId, payload, teste = false) =>
    http.post(`/admin/credenciamento/projetos/${projetoId}`, payload, { params: comTeste({}, teste) })
        .then((r) => r.data);

// --- Parametrização → Credenciamento ---

export const getParametrizacaoCredenciamento = () =>
    http.get('/admin/credenciamento').then((r) => r.data.data);

export const definirJanelaEvento = (eventoDe, eventoAte) =>
    http.patch('/admin/credenciamento/janela', { evento_de: eventoDe || null, evento_ate: eventoAte || null })
        .then((r) => r.data.data);

/** Itens entregues ao finalista no balcão (camiseta, crachá, kit…). */
export const definirItensCredenciamento = (itens) =>
    http.patch('/admin/credenciamento/itens', { itens }).then((r) => r.data.data);

export const criarDocumentoCredenciamento = (payload) =>
    http.post('/admin/credenciamento/documentos', payload).then((r) => r.data.data);

export const atualizarDocumentoCredenciamento = (id, payload) =>
    http.put(`/admin/credenciamento/documentos/${id}`, payload).then((r) => r.data.data);

export const excluirDocumentoCredenciamento = (id) =>
    http.delete(`/admin/credenciamento/documentos/${id}`).then((r) => r.data.data);

// --- Credenciamento → Contas temporárias ---
// Contas de prazo curto para quem atende o balcão sem ser da organização. Toda
// ação devolve a lista inteira: criar/renovar/desativar pode vencer outras
// contas na mesma passada.

export const getContasTemporarias = () =>
    http.get('/admin/credenciamento/contas').then((r) => r.data.data);

export const criarContaTemporaria = (payload) =>
    http.post('/admin/credenciamento/contas', payload).then((r) => r.data);

export const renovarContaTemporaria = (id, prazo) =>
    http.patch(`/admin/credenciamento/contas/${id}/renovar`, prazo).then((r) => r.data);

export const desativarContaTemporaria = (id) =>
    http.patch(`/admin/credenciamento/contas/${id}/desativar`).then((r) => r.data);
