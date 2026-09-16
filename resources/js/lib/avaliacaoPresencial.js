import http from './http.js';

// Aba "Presencial" do avaliador: a intenção de avaliar no dia da feira e as
// orientações que a organização mostra a quem aceita. `teste` só tem efeito
// para a conta demo — é o modo que ignora as datas do evento.
const params = (teste) => ({ params: teste ? { teste: 1 } : {} });

export const getPresencial = (teste = false) =>
    http.get('/avaliador/presencial', params(teste)).then((r) => r.data.data);

export const responderPresencial = (presencial, teste = false) =>
    http.put('/avaliador/presencial', { presencial, teste: teste ? 1 : 0 })
        .then((r) => r.data);

// --- A avaliação no estande (durante o evento) -------------------------------

export const getPainelPresencial = (teste = false) =>
    http.get('/avaliador/presencial/avaliacoes', params(teste)).then((r) => r.data.data);

export const iniciarAvaliacaoPresencial = (projetoId, teste = false) =>
    http.post(`/avaliador/presencial/avaliacoes/projetos/${projetoId}`, { teste: teste ? 1 : 0 })
        .then((r) => r.data.data);

export const getAvaliacaoPresencial = (id, teste = false) =>
    http.get(`/avaliador/presencial/avaliacoes/${id}`, params(teste)).then((r) => r.data.data);

export const salvarRascunhoPresencial = (id, dados, teste = false) =>
    http.post(`/avaliador/presencial/avaliacoes/${id}/rascunho`, { ...dados, teste: teste ? 1 : 0 })
        .then((r) => r.data);

export const concluirAvaliacaoPresencial = (id, dados, teste = false) =>
    http.post(`/avaliador/presencial/avaliacoes/${id}/concluir`, { ...dados, teste: teste ? 1 : 0 })
        .then((r) => r.data);
