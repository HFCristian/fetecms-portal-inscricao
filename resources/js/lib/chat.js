import http from './http.js';

// Uma mensagem foi vista pelo outro lado se ele visualizou a conversa em um
// momento igual ou posterior ao envio dela. `outroLadoVistoEm` é o carimbo de
// leitura do destinatário (suporte_visto_em para mensagens do usuário, e
// usuario_visto_em para mensagens do suporte).
export function foiVista(mensagemCriadaEm, outroLadoVistoEm) {
    if (!mensagemCriadaEm || !outroLadoVistoEm) return false;
    const criada = new Date(mensagemCriadaEm).getTime();
    const visto = new Date(outroLadoVistoEm).getTime();
    if (Number.isNaN(criada) || Number.isNaN(visto)) return false;
    return visto >= criada;
}

// Chat de suporte — orientador/avaliador
export const getMinhaConversa = () => http.get('/chat/conversa').then((r) => r.data.data);
export const enviarMensagem = (corpo) => http.post('/chat/mensagens', { corpo }).then((r) => r.data.data);
// Consulta leve (não marca leitura) p/ a bolinha do botão fechado.
export const getNaoLidas = () => http.get('/chat/nao-lidas').then((r) => r.data.data);

// Badge do menu admin: quantas conversas estão "não visualizadas".
export const getConversasNaoVistas = () => http.get('/admin/conversas-nao-vistas').then((r) => r.data.data);

// Chat de suporte — admin (inbox). getConversas devolve { data: grupos, meta: { contagem } }.
export const getConversas = () => http.get('/admin/conversas').then((r) => r.data);
export const getConversa = (id) => http.get(`/admin/conversas/${id}`).then((r) => r.data.data);
export const responderConversa = (id, corpo) =>
    http.post(`/admin/conversas/${id}/responder`, { corpo }).then((r) => r.data.data);
export const atualizarStatusConversa = (id, status) =>
    http.patch(`/admin/conversas/${id}/status`, { status }).then((r) => r.data.data);
