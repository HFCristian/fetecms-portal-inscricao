import http from './http.js';

export const getDashboard = () => http.get('/admin/dashboard').then((r) => r.data.data);

export const getAvaliadores = () => http.get('/admin/avaliadores').then((r) => r.data.data);

// Avaliação online (E7): avaliadores por área e projetos submetidos por área.
export const getAvaliacaoAvaliadores = () => http.get('/admin/avaliacao/avaliadores').then((r) => r.data.data);
export const getAvaliacaoProjetos = () => http.get('/admin/avaliacao/projetos').then((r) => r.data.data);

export const getProjetosPorArea = () => http.get('/admin/projetos-por-area').then((r) => r.data.data);

export const getProjetosPorLocalidade = () => http.get('/admin/projetos-por-localidade').then((r) => r.data.data);

export const criarAdmin = (payload) => http.post('/admin/admins', payload).then((r) => r.data.data);

// Gestão de administradores (listar, editar nome/email, ativar/desativar).
export const getAdmins = () => http.get('/admin/admins').then((r) => r.data.data);
export const atualizarAdmin = (id, payload) => http.put(`/admin/admins/${id}`, payload).then((r) => r.data.data);
export const definirStatusAdmin = (id, isActive) =>
    http.patch(`/admin/admins/${id}/status`, { is_active: isActive }).then((r) => r.data.data);

// Parametrização do catálogo (áreas/subáreas). Toda mutação devolve a árvore atualizada.
export const getCatalogo = () => http.get('/admin/catalogo').then((r) => r.data.data);
export const renomearArea = (id, nome) => http.put(`/admin/areas/${id}`, { nome }).then((r) => r.data.data);
export const mesclarArea = (id, destinoId) => http.post(`/admin/areas/${id}/mesclar`, { destino_id: destinoId }).then((r) => r.data.data);
export const excluirArea = (id) => http.delete(`/admin/areas/${id}`).then((r) => r.data.data);
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
