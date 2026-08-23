import http from './http.js';

/** Estado da janela de inscrição para quem está logado (explica a área só de leitura). */
export const getInscricoes = () => http.get('/inscricoes').then((r) => r.data.data);

/** Mesma janela, sem login — a tela de cadastro precisa saber se já abriu. */
export const getInscricoesPublico = () => http.get('/inscricoes/publico').then((r) => r.data.data);
