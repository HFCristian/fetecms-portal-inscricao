import http from './http.js';

export const getDashboard = () => http.get('/admin/dashboard').then((r) => r.data.data);

export const getProjetosPorArea = () => http.get('/admin/projetos-por-area').then((r) => r.data.data);

export const criarAdmin = (payload) => http.post('/admin/admins', payload).then((r) => r.data.data);

// Parametrização do catálogo (áreas/subáreas). Toda mutação devolve a árvore atualizada.
export const getCatalogo = () => http.get('/admin/catalogo').then((r) => r.data.data);
export const renomearArea = (id, nome) => http.put(`/admin/areas/${id}`, { nome }).then((r) => r.data.data);
export const mesclarArea = (id, destinoId) => http.post(`/admin/areas/${id}/mesclar`, { destino_id: destinoId }).then((r) => r.data.data);
export const excluirArea = (id) => http.delete(`/admin/areas/${id}`).then((r) => r.data.data);
export const renomearSubarea = (id, nome) => http.put(`/admin/subareas/${id}`, { nome }).then((r) => r.data.data);
export const mesclarSubarea = (id, destinoId) => http.post(`/admin/subareas/${id}/mesclar`, { destino_id: destinoId }).then((r) => r.data.data);
export const excluirSubarea = (id) => http.delete(`/admin/subareas/${id}`).then((r) => r.data.data);
