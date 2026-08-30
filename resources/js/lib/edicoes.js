import http from './http.js';

/** O seletor de edição: o que existe e qual o usuário está vendo. */
export const getEdicoes = () => http.get('/edicoes').then((r) => r.data.data);

/**
 * Troca a edição do usuário. `null` volta a seguir a padrão do portal.
 * Muda TODO o escopo (projetos, prazos, limites), então quem chama recarrega
 * a tela em seguida.
 */
export const trocarEdicao = (edicaoId) =>
    http.put('/edicoes/atual', { edicao_id: edicaoId ?? null }).then((r) => r.data.data);

// --- Parametrização → Edições (admin) ---

export const getEdicoesAdmin = () => http.get('/admin/edicoes').then((r) => r.data.data);

export const criarEdicao = (payload) => http.post('/admin/edicoes', payload).then((r) => r.data.data);

export const atualizarEdicao = (id, payload) =>
    http.put(`/admin/edicoes/${id}`, payload).then((r) => r.data.data);

export const definirEdicaoPadrao = (id) =>
    http.patch(`/admin/edicoes/${id}/padrao`).then((r) => r.data.data);

export const excluirEdicao = (id) => http.delete(`/admin/edicoes/${id}`).then((r) => r.data.data);
