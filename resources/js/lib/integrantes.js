import http from './http.js';

export const getIntegrantes = (projetoId) =>
    http.get(`/projetos/${projetoId}/integrantes`).then((r) => r.data.data);

export const criarAluno = (projetoId, data) =>
    http.post(`/projetos/${projetoId}/alunos`, data).then((r) => r.data.data);

export const atualizarAluno = (alunoId, data) =>
    http.put(`/alunos/${alunoId}`, data).then((r) => r.data.data);

export const removerAluno = (alunoId) => http.delete(`/alunos/${alunoId}`);

export const salvarCoorientador = (projetoId, data) =>
    http.put(`/projetos/${projetoId}/coorientador`, data).then((r) => r.data.data);

export const removerCoorientador = (projetoId) =>
    http.delete(`/projetos/${projetoId}/coorientador`);
