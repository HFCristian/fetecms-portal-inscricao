import http from './http.js';

// Avaliação online — lado do avaliador (E7). `teste` (demo) ignora a data de liberação.
const qs = (teste) => ({ params: teste ? { teste: 1 } : {} });

export const getMinhaAvaliacao = (teste = false) => http.get('/avaliacao', qs(teste)).then((r) => r.data.data);
export const getAvaliacao = (id, teste = false) => http.get(`/avaliacao/${id}`, qs(teste)).then((r) => r.data.data);
export const iniciarAvaliacao = (id, teste = false) => http.post(`/avaliacao/${id}/iniciar`, {}, qs(teste)).then((r) => r.data.data);
// `preenchimento`: { respostas: { chave: valor }, comentario_video, comentario_projeto,
// area_correta, area_sugerida_id, ... }. A nota final (soma ponderada) sai no backend.
export const concluirAvaliacao = (id, preenchimento, teste = false) =>
    http.post(`/avaliacao/${id}/concluir`, preenchimento, qs(teste)).then((r) => r.data.data);

// Salva o preenchimento parcial sem enviar: nada é obrigatório no rascunho.
export const salvarRascunhoAvaliacao = (id, preenchimento, teste = false) =>
    http.post(`/avaliacao/${id}/rascunho`, preenchimento, qs(teste)).then((r) => r.data.data);
