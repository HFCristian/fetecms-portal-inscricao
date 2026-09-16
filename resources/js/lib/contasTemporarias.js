import http from './http.js';

/**
 * Contas temporárias dos balcões do evento.
 *
 * O `setor` é a aba a que a conta pertence — `credenciamento`, `almoxarifado`
 * ou `avaliacao_presencial` (os voluntários) —, e é ele que separa as listas:
 * cada aba cadastra, renova e encerra **só as suas**, e a conta criada abre
 * somente aquela aba.
 *
 * Toda ação devolve a lista inteira: criar, renovar e desativar mudam a
 * situação de uma linha e podem vencer outras na mesma passada.
 */

// O prefixo da rota nem sempre é o nome do setor: a aba "Avaliação presencial"
// mora em /admin/presencial, e o setor precisa casar com o enum AbaAdmin.
const PREFIXO = { avaliacao_presencial: 'presencial' };

const base = (setor) => `/admin/${PREFIXO[setor] ?? setor}/contas`;

export const getContasTemporarias = (setor = 'credenciamento') =>
    http.get(base(setor)).then((r) => r.data.data);

export const criarContaTemporaria = (payload, setor = 'credenciamento') =>
    http.post(base(setor), payload).then((r) => r.data);

export const renovarContaTemporaria = (id, prazo, setor = 'credenciamento') =>
    http.patch(`${base(setor)}/${id}/renovar`, prazo).then((r) => r.data);

export const desativarContaTemporaria = (id, setor = 'credenciamento') =>
    http.patch(`${base(setor)}/${id}/desativar`).then((r) => r.data);
