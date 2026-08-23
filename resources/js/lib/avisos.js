import http from './http.js';

/** Card de aviso publicado pelo admin (lado de quem lê). */
export const getAvisoAtivo = () => http.get('/avisos/ativo').then((r) => r.data.data);
export const marcarAvisoVisto = (id) => http.post(`/avisos/${id}/visto`).then((r) => r.data.data);
export const fecharAviso = (id) => http.post(`/avisos/${id}/fechar`).then((r) => r.data.data);
