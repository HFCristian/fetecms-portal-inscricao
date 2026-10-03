import http from './http.js';

// Aba "Suporte no evento" do orientador (Sprint 162): acompanhante e
// intérpretes para os projetos finalistas. `teste` só vale para a conta demo.
const params = (teste) => ({ params: teste ? { teste: 1 } : {} });

export const getSuporte = (teste = false) =>
    http.get('/suporte', params(teste)).then((r) => r.data.data);

export const pedirSuporte = (projetoId, payload, teste = false) =>
    http.post(`/suporte/projetos/${projetoId}`, payload, params(teste)).then((r) => r.data);

export const alterarSuporte = (suporteId, payload, teste = false) =>
    http.put(`/suporte/${suporteId}`, payload, params(teste)).then((r) => r.data);

export const excluirSuporte = (suporteId, teste = false) =>
    http.delete(`/suporte/${suporteId}`, params(teste)).then((r) => r.data);
