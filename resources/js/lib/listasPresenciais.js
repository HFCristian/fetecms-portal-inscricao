import http from './http.js';
import { baixarArquivo } from './presencial.js';

/**
 * Ranking presencial → listas preliminares e finais (Sprint 251).
 *
 * `teste` só vale para a conta demo: escolhe a trilha do ensaio (a lista final
 * demo) na relação e na criação. Uma lista já criada carrega a trilha dela.
 */
const comTeste = (teste) => (teste ? { params: { teste: 1 } } : {});

export const getListasPresenciais = (teste = false) =>
    http.get('/admin/presencial/listas', comTeste(teste)).then((r) => r.data.data);

export const getOpcoesListaPresencial = (teste = false) =>
    http.get('/admin/presencial/listas/opcoes', comTeste(teste)).then((r) => r.data.data);

/** Monta o rascunho pela classificação; devolve a tela da lista nova. */
export const gerarListaPresencial = (payload, teste = false) =>
    http.post('/admin/presencial/listas', { ...payload, ...(teste ? { teste: 1 } : {}) }).then((r) => r.data.data);

/** Monta o rascunho pela união de listas já geradas. */
export const montarListaPresencialPorUniao = ({ listas, tipo, nome, descricao }, teste = false) =>
    http.post('/admin/presencial/listas/uniao', { listas, tipo, nome, descricao, ...(teste ? { teste: 1 } : {}) })
        .then((r) => r.data.data);

export const getListaPresencial = (id) =>
    http.get(`/admin/presencial/listas/${id}`).then((r) => r.data.data);

export const rotularListaPresencial = (id, dados) =>
    http.patch(`/admin/presencial/listas/${id}`, dados).then((r) => r.data);

export const gerarListaPresencialDefinitiva = (id) =>
    http.post(`/admin/presencial/listas/${id}/gerar`).then((r) => r.data);

export const reativarListaPresencial = (id, justificativa) =>
    http.post(`/admin/presencial/listas/${id}/reativar`, { justificativa }).then((r) => r.data);

/** TXT, CSV, Excel ou PDF da lista, com o nome que o servidor mandar. */
export const exportarListaPresencial = (id, formato) =>
    baixarArquivo(`/admin/presencial/listas/${id}/exportar/${formato}`, {}, `lista-presencial.${formato}`);

// Incluir e retirar da seção por nota (Sprint 252): livre no rascunho; depois
// de gerada, com justificativa. Devolvem a tela da lista.

export const adicionarNaListaPresencial = (id, projetoId, justificativa = null) =>
    http.post(`/admin/presencial/listas/${id}/projetos`, { projeto_id: projetoId, ...(justificativa ? { justificativa } : {}) })
        .then((r) => r.data.data);

export const removerDaListaPresencial = (id, projetoId, justificativa = null) =>
    http.delete(`/admin/presencial/listas/${id}/projetos/${projetoId}`, { data: justificativa ? { justificativa } : {} })
        .then((r) => r.data.data);

// Premiações da lista (Sprint 253): a cópia de Credenciais e Prêmios, editável
// aqui sem mexer no cadastro original.

export const adicionarNaPremiacaoPresencial = (id, premioId, projetoId, justificativa = null) =>
    http.post(`/admin/presencial/listas/${id}/premiacoes/${premioId}/projetos`, {
        projeto_id: projetoId, ...(justificativa ? { justificativa } : {}),
    }).then((r) => r.data.data);

export const removerDaPremiacaoPresencial = (id, premioId, projetoId, justificativa = null) =>
    http.delete(`/admin/presencial/listas/${id}/premiacoes/${premioId}/projetos/${projetoId}`, {
        data: justificativa ? { justificativa } : {},
    }).then((r) => r.data.data);

export const recarregarPremiacoesPresencial = (id, justificativa = null) =>
    http.post(`/admin/presencial/listas/${id}/premiacoes/recarregar`, justificativa ? { justificativa } : {})
        .then((r) => r.data.data);
