import http from './http.js';

export const listarProjetos = (status) =>
    http.get('/projetos', { params: status ? { status } : {} }).then((r) => r.data.data);

export const obterProjeto = (id) => http.get(`/projetos/${id}`).then((r) => r.data.data);

export const criarProjeto = (data) => http.post('/projetos', data).then((r) => r.data.data);

export const atualizarProjeto = (id, data) =>
    http.put(`/projetos/${id}`, data).then((r) => r.data.data);

export const removerProjeto = (id) => http.delete(`/projetos/${id}`);

/** Desfaz a submissão: o projeto volta a rascunho, editável e submissível de novo. */
export const cancelarSubmissao = (id) =>
    http.post(`/projetos/${id}/cancelar-submissao`).then((r) => r.data.data);
