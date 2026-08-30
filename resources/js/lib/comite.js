import http from './http.js';

/**
 * Comitê especial → Transporte de comitê.
 *
 * A posição vai para o servidor de 5 em 5 segundos (`enviarPonto`) e o mapa lê
 * o estado atual no mesmo intervalo — não há WebSocket no projeto, as duas
 * pontas são polling.
 */

/** Minha sessão de localizador (ou null) + as opções do assistente. */
export const getMinhaLocalizacao = () =>
    http.get('/admin/comite/localizacao').then((r) => r.data.data);

/** Liga o localizador com o que o assistente coletou. */
export const iniciarLocalizacao = (payload) =>
    http.post('/admin/comite/localizacao', payload).then((r) => r.data.data);

/** Ajusta por quanto tempo o localizador ainda fica ligado. */
export const prorrogarLocalizacao = (minutos) =>
    http.patch('/admin/comite/localizacao', { minutos }).then((r) => r.data.data);

/** Desliga o localizador (e apaga o trajeto). */
export const encerrarLocalizacao = () =>
    http.delete('/admin/comite/localizacao').then((r) => r.data);

/** Uma posição do dispositivo, com a estimativa de rota calculada no navegador. */
export const enviarPonto = (ponto) =>
    http.post('/admin/comite/localizacao/ponto', ponto).then((r) => r.data.data);

/** Todos os localizadores ligados agora. */
export const getMapaComite = () => http.get('/admin/comite/mapa').then((r) => r.data);

/** O detalhe de um ponto do mapa, com o trajeto percorrido. */
export const getPontoComite = (id) =>
    http.get(`/admin/comite/mapa/${id}`).then((r) => r.data.data);
