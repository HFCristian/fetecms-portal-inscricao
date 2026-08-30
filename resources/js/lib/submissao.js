import http from './http.js';

export const getResumo = (projetoId) =>
    http.get(`/projetos/${projetoId}/resumo`).then((r) => r.data.data);

/**
 * Submete a inscrição. A justificativa só é exigida quando quem submete é um
 * ADMIN terminando o rascunho de outra pessoa — o backend valida a mesma regra
 * (`data.exige_justificativa` no resumo diz quando a tela precisa pedi-la).
 */
export const submeterProjeto = (projetoId, justificativa) =>
    http.post(`/projetos/${projetoId}/submeter`, justificativa ? { justificativa } : {}).then((r) => r.data);
