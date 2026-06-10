import http from './http.js';

export const listarProjetos = (status) =>
    http.get('/projetos', { params: status ? { status } : {} }).then((r) => r.data.data);

export const obterProjeto = (id) => http.get(`/projetos/${id}`).then((r) => r.data.data);

export const criarProjeto = (data) => http.post('/projetos', data).then((r) => r.data.data);

export const atualizarProjeto = (id, data) =>
    http.put(`/projetos/${id}`, data).then((r) => r.data.data);

export const removerProjeto = (id) => http.delete(`/projetos/${id}`);
