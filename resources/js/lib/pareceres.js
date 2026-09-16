import http from './http.js';

// Aba "Pareceres" do orientador: a nota média de cada projeto e o que os
// avaliadores escreveram. `teste` só tem efeito para o orientador demo — é o
// modo que ignora as datas da janela, como na aba Ajustes.
const params = (teste) => ({ params: teste ? { teste: 1 } : {} });

export const getPareceres = (teste = false) =>
    http.get('/pareceres', params(teste)).then((r) => r.data.data);

export const getParecerProjeto = (projetoId, teste = false) =>
    http.get(`/pareceres/projetos/${projetoId}`, params(teste)).then((r) => r.data.data);
