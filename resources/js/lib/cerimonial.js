import http from './http.js';

/**
 * Aba **Cerimonial**: o check-in da cerimônia de premiação e o painel.
 *
 * `teste` só tem efeito para o admin demo — liga o modo de teste, que ignora a
 * janela do evento, usa a lista final de demonstração e isola os check-ins do
 * ensaio dos de verdade.
 *
 * `evento` é o **evento de cerimônia** (o id): o check-in, a busca e a ficha
 * precisam de um; a Visão Geral e os premiados aceitam `'todos'` (ou nada),
 * que soma o público de todos os eventos.
 */
const params = (extra = {}, teste = false, evento = null) => ({
    ...extra,
    ...(teste ? { teste: 1 } : {}),
    ...(typeof evento === 'number' ? { evento_id: evento } : {}),
});

export const getCerimonialConfig = (teste = false) =>
    http.get('/admin/cerimonial/config', { params: params({}, teste) }).then((r) => r.data.data);

/** Os eventos de cerimônia da edição: nome, categorias e público. */
export const getEventosCerimonia = () =>
    http.get('/admin/cerimonial/eventos').then((r) => r.data.data);

/** Cria um evento: `{ nome, categorias: ['fetecms', …] }`. */
export const criarEventoCerimonia = (dados) =>
    http.post('/admin/cerimonial/eventos', dados).then((r) => r.data.data);

export const atualizarEventoCerimonia = (id, dados) =>
    http.put(`/admin/cerimonial/eventos/${id}`, dados).then((r) => r.data.data);

/** Exclui (só o que ainda não recebeu ninguém) e devolve a lista que sobrou. */
export const excluirEventoCerimonia = (id) =>
    http.delete(`/admin/cerimonial/eventos/${id}`).then((r) => r.data.data);

/**
 * O crachá lido na porta: registra a entrada daquela pessoa no evento e
 * devolve quem é (`ja_presente` quando ela já tinha entrado, com a hora em
 * `checkin_em`).
 */
export const checkinPorCodigo = (codigo, teste = false, evento = null) =>
    http.post('/admin/cerimonial/checkin-codigo', { codigo }, { params: params({}, teste, evento) })
        .then((r) => r.data.data);

/** Busca por nome ou CPF entre os participantes do público do evento. */
export const buscarParticipantes = (busca, teste = false, evento = null) =>
    http.get('/admin/cerimonial/busca', { params: params({ busca }, teste, evento) }).then((r) => r.data.data);

/** A ficha de um projeto no evento: integrantes e quem já entrou. */
export const getFichaCerimonial = (projetoId, teste = false, evento = null) =>
    http.get(`/admin/cerimonial/projetos/${projetoId}`, { params: params({}, teste, evento) }).then((r) => r.data);

/** Check-in de uma ou mais pessoas: `participantes` são chaves "A45"/"O12". */
export const registrarCheckin = (projetoId, participantes, teste = false, evento = null) =>
    http.post(`/admin/cerimonial/projetos/${projetoId}/checkin`, { participantes }, { params: params({}, teste, evento) })
        .then((r) => r.data.data);

/** Desfaz o check-in de uma pessoa — justificativa obrigatória. */
export const desfazerCheckin = (projetoId, participante, justificativa, teste = false, evento = null) =>
    http.post(
        `/admin/cerimonial/projetos/${projetoId}/desfazer`,
        { participante, justificativa },
        { params: params({}, teste, evento) },
    ).then((r) => r.data.data);

/** Os cards do painel — só os números (é o que o polling recarrega). */
export const getVisaoGeral = (teste = false, evento = null) =>
    http.get('/admin/cerimonial/visao-geral', { params: params({}, teste, evento) }).then((r) => r.data);

/** A lista nominal de um card: quem chegou e quem falta. */
export const getDetalheCard = (card, teste = false, evento = null) =>
    http.get('/admin/cerimonial/visao-geral/detalhe', { params: params({ card }, teste, evento) })
        .then((r) => r.data.data);

/** Um cartão por projeto premiado, com o ícone de cada integrante. */
export const getPremiados = (teste = false, evento = null) =>
    http.get('/admin/cerimonial/premiados', { params: params({}, teste, evento) }).then((r) => r.data.data);

/** De quantos em quantos segundos o painel se atualiza (null desliga). */
export const definirAtualizacao = (segundos) =>
    http.patch('/admin/cerimonial/atualizacao', { segundos }).then((r) => r.data.data);
