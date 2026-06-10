import http from './http.js';

export const getDashboard = () => http.get('/admin/dashboard').then((r) => r.data.data);

export const getProjetosPorArea = () => http.get('/admin/projetos-por-area').then((r) => r.data.data);

export const criarAdmin = (payload) => http.post('/admin/admins', payload).then((r) => r.data.data);
