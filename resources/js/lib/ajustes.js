import http from './http.js';

// Aba "Ajustes" do orientador: as sugestões que os avaliadores deixaram nos
// projetos dele. `teste` só tem efeito para o orientador demo — é o modo que
// ignora as datas do período para demonstrar o fluxo.
const params = (teste) => ({ params: teste ? { teste: 1 } : {} });

export const getAjustes = (teste = false) =>
    http.get('/ajustes', params(teste)).then((r) => r.data.data);

export const getAjustesProjeto = (projetoId, teste = false) =>
    http.get(`/ajustes/projetos/${projetoId}`, params(teste)).then((r) => r.data.data);

/** Aceita (ou desfaz) uma sugestão de área/subárea. */
export const decidirAjuste = (projetoId, payload, teste = false) =>
    http.post(`/ajustes/projetos/${projetoId}/decidir`, { ...payload, teste: teste ? 1 : 0 })
        .then((r) => r.data);
