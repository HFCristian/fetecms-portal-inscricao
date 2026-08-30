import http from './http.js';

// ------------------------------------------------------------------ //
// Lado de quem responde                                               //
// ------------------------------------------------------------------ //

/** O feedback pendente desta pessoa (null quando não há nenhum). */
export const getFeedbackPendente = () =>
    http.get('/feedbacks/pendente').then((r) => r.data.data);

/** Todos os pendentes, inclusive os dispensados — a lista do perfil. */
export const getMeusFeedbacks = () => http.get('/feedbacks').then((r) => r.data.data);

export const marcarFeedbackVisto = (id) => http.post(`/feedbacks/${id}/visto`).then((r) => r.data);

export const dispensarFeedback = (id) => http.post(`/feedbacks/${id}/dispensar`).then((r) => r.data);

export const responderFeedback = (id, respostas) =>
    http.post(`/feedbacks/${id}/responder`, { respostas }).then((r) => r.data);

// ------------------------------------------------------------------ //
// Lado do admin                                                       //
// ------------------------------------------------------------------ //

export const getFeedbacks = () => http.get('/admin/feedbacks').then((r) => r.data.data);

/** Públicos e modelos de alternativas que o formulário oferece. */
export const getOpcoesFeedback = () => http.get('/admin/feedbacks/opcoes').then((r) => r.data.data);

export const criarFeedback = (payload) => http.post('/admin/feedbacks', payload).then((r) => r.data);

export const getFeedback = (id) => http.get(`/admin/feedbacks/${id}`).then((r) => r.data.data);

export const getDestinatariosFeedback = (id, situacao) =>
    http.get(`/admin/feedbacks/${id}/destinatarios`, { params: { situacao: situacao || undefined } })
        .then((r) => r.data);

export const reenviarFalhasFeedback = (id) =>
    http.post(`/admin/feedbacks/${id}/reenviar-falhas`).then((r) => r.data);

export const encerrarFeedback = (id) => http.post(`/admin/feedbacks/${id}/encerrar`).then((r) => r.data);

/** Baixa o CSV com as contagens e as respostas escritas. */
export async function exportarFeedback(id) {
    const r = await http.get(`/admin/feedbacks/${id}/exportar`, { responseType: 'blob' });
    const url = URL.createObjectURL(r.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = /filename="([^"]+)"/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? `feedback-${id}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
