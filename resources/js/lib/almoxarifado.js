import http from './http.js';

/**
 * Aba Almoxarifado: a guarda de volumes dos finalistas durante a feira.
 *
 * `teste` só tem efeito para a conta demo: liga o modo de teste, que ignora a
 * janela do evento, usa a lista final **demo** e isola os registros do ensaio.
 */
const comTeste = (params = {}, teste = false) => (teste ? { ...params, teste: 1 } : params);

export const getAlmoxarifadoConfig = (teste = false) =>
    http.get('/admin/almoxarifado/config', { params: comTeste({}, teste) }).then((r) => r.data.data);

/** A tabela de registros. `filtros`: { busca, situacao, page }. */
export const getAlmoxarifadoRegistros = (filtros = {}, teste = false) =>
    http.get('/admin/almoxarifado/registros', { params: comTeste(filtros, teste) }).then((r) => r.data);

/** Os finalistas que o balcão atende, com as pessoas de cada um. */
export const getAlmoxarifadoProjetos = (busca = '', teste = false) =>
    http.get('/admin/almoxarifado/projetos', { params: comTeste({ busca }, teste) }).then((r) => r.data.data);

/**
 * Grava a guarda confirmada no último passo do assistente.
 * `payload`: { projeto_id, responsavel_tipo, responsavel_id, itens: [] }.
 */
export const guardarNoAlmoxarifado = (payload, teste = false) =>
    http.post('/admin/almoxarifado/registros', payload, { params: comTeste({}, teste) }).then((r) => r.data.data);

export const getAlmoxarifadoRegistro = (id, teste = false) =>
    http.get(`/admin/almoxarifado/registros/${id}`, { params: comTeste({}, teste) }).then((r) => r.data.data);

/**
 * Registra a retirada. Sem `itens`, sai tudo o que ainda está guardado (a
 * retirada completa); com eles, só os escolhidos (a parcial).
 * `payload`: { responsavel_tipo, responsavel_id, itens?: [ids] }.
 */
export const retirarDoAlmoxarifado = (id, payload, teste = false) =>
    http.post(`/admin/almoxarifado/registros/${id}/retiradas`, payload, { params: comTeste({}, teste) })
        .then((r) => r.data.data);

/**
 * Corrige o registro. `payload`: { responsavel_tipo, responsavel_id,
 * itens: [{ id?, descricao }], justificativa }.
 */
export const editarRegistroAlmoxarifado = (id, payload, teste = false) =>
    http.put(`/admin/almoxarifado/registros/${id}`, payload, { params: comTeste({}, teste) })
        .then((r) => r.data.data);

/** Exclui o registro (soft delete), com justificativa obrigatória. */
export const excluirRegistroAlmoxarifado = (id, justificativa, teste = false) =>
    http.delete(`/admin/almoxarifado/registros/${id}`, {
        params: comTeste({}, teste),
        data: { justificativa },
    }).then((r) => r.data);
