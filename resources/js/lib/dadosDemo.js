import http from './http.js';

/**
 * Parametrização → **Dados de demonstração**.
 *
 * Todas as ações devolvem o panorama inteiro em `data` (e a mensagem em
 * `meta.message`): apagar uma conta derruba projetos, avaliações e registros
 * junto, então recarregar só o grupo mexido deixaria a tela mentindo sobre os
 * outros.
 */
export const getDadosDemo = () => http.get('/admin/demo').then((r) => r.data);

/** Liga/desliga a marca de demonstração de uma conta (qualquer papel). */
export const definirContaDemo = (userId, demo) =>
    http.patch(`/admin/demo/contas/${userId}`, { demo }).then((r) => r.data);

export const excluirContaDemo = (userId) => http.delete(`/admin/demo/contas/${userId}`).then((r) => r.data);

export const excluirProjetoDemo = (projetoId) =>
    http.delete(`/admin/demo/projetos/${projetoId}`).then((r) => r.data);

export const excluirListaDemo = (listaId) => http.delete(`/admin/demo/listas/${listaId}`).then((r) => r.data);

export const excluirGuardaDemo = (guardaId) => http.delete(`/admin/demo/guardas/${guardaId}`).then((r) => r.data);

/** Apaga tudo que é de demonstração de uma vez. */
export const limparDadosDemo = () => http.post('/admin/demo/limpar').then((r) => r.data);
