import http from './http.js';

// Chat de suporte — orientador/avaliador
export const getMinhaConversa = () => http.get('/chat/conversa').then((r) => r.data.data);
export const enviarMensagem = (corpo) => http.post('/chat/mensagens', { corpo }).then((r) => r.data.data);

// Chat de suporte — admin (inbox). getConversas devolve { data: grupos, meta: { contagem } }.
export const getConversas = () => http.get('/admin/conversas').then((r) => r.data);
export const getConversa = (id) => http.get(`/admin/conversas/${id}`).then((r) => r.data.data);
export const responderConversa = (id, corpo) =>
    http.post(`/admin/conversas/${id}/responder`, { corpo }).then((r) => r.data.data);
export const atualizarStatusConversa = (id, status) =>
    http.patch(`/admin/conversas/${id}/status`, { status }).then((r) => r.data.data);
