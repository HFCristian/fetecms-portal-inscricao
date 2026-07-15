import http from './http.js';

// Avaliação online — lado do avaliador (projetos designados, após a liberação).
export const getMinhaAvaliacao = () => http.get('/avaliacao').then((r) => r.data.data);
