import http from './http.js';

// Avaliação online — lado do avaliador (E7). `teste` (demo) ignora a data de liberação.
const qs = (teste) => ({ params: teste ? { teste: 1 } : {} });

export const getMinhaAvaliacao = (teste = false) => http.get('/avaliacao', qs(teste)).then((r) => r.data.data);
export const getAvaliacao = (id, teste = false) => http.get(`/avaliacao/${id}`, qs(teste)).then((r) => r.data.data);
export const iniciarAvaliacao = (id, teste = false) => http.post(`/avaliacao/${id}/iniciar`, {}, qs(teste)).then((r) => r.data.data);
// `rubrica`: { nota_video, comentario_video, ..., area_correta, area_sugerida_id, ... }
// A nota final (soma dos quesitos) é calculada no backend.
export const concluirAvaliacao = (id, rubrica, teste = false) =>
    http.post(`/avaliacao/${id}/concluir`, rubrica, qs(teste)).then((r) => r.data.data);

// Salva o preenchimento parcial sem enviar: nada é obrigatório no rascunho.
export const salvarRascunhoAvaliacao = (id, rubrica, teste = false) =>
    http.post(`/avaliacao/${id}/rascunho`, rubrica, qs(teste)).then((r) => r.data.data);
