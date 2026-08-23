import http from './http.js';

/** Estado do prazo de submissão para quem está logado (explica a área só de leitura). */
export const getInscricoes = () => http.get('/inscricoes').then((r) => r.data.data);
