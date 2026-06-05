import http from './http.js';

export const getResumo = (projetoId) =>
    http.get(`/projetos/${projetoId}/resumo`).then((r) => r.data.data);

export const submeterProjeto = (projetoId) =>
    http.post(`/projetos/${projetoId}/submeter`).then((r) => r.data);
