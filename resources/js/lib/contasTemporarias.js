import http from './http.js';

/**
 * Contas temporárias dos balcões do evento.
 *
 * O `setor` é a aba a que a conta pertence — `credenciamento` ou
 * `almoxarifado` —, e é ele que separa as duas listas: cada aba cadastra,
 * renova e encerra **só as suas**, e a conta criada abre somente aquela aba.
 *
 * Toda ação devolve a lista inteira: criar, renovar e desativar mudam a
 * situação de uma linha e podem vencer outras na mesma passada.
 */
export const getContasTemporarias = (setor = 'credenciamento') =>
    http.get(`/admin/${setor}/contas`).then((r) => r.data.data);

export const criarContaTemporaria = (payload, setor = 'credenciamento') =>
    http.post(`/admin/${setor}/contas`, payload).then((r) => r.data);

export const renovarContaTemporaria = (id, prazo, setor = 'credenciamento') =>
    http.patch(`/admin/${setor}/contas/${id}/renovar`, prazo).then((r) => r.data);

export const desativarContaTemporaria = (id, setor = 'credenciamento') =>
    http.patch(`/admin/${setor}/contas/${id}/desativar`).then((r) => r.data);
