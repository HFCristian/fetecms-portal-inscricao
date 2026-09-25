import http from './http.js';

/**
 * Aba **Cerimonial**: o check-in da cerimônia de premiação e o painel.
 *
 * `teste` só tem efeito para o admin demo — liga o modo de teste, que ignora a
 * janela do evento, usa a lista final de demonstração e isola os check-ins do
 * ensaio dos de verdade.
 */
const comTeste = (params = {}, teste = false) => (teste ? { ...params, teste: 1 } : params);

export const getCerimonialConfig = (teste = false) =>
    http.get('/admin/cerimonial/config', { params: comTeste({}, teste) }).then((r) => r.data.data);

/** O crachá lido no balcão: devolve de quem é e que projeto abrir. */
export const lerCodigoCerimonial = (codigo, teste = false) =>
    http.post('/admin/cerimonial/codigo', comTeste({ codigo }, teste)).then((r) => r.data.data);

/** Busca por nome ou CPF entre os participantes dos projetos finalistas. */
export const buscarParticipantes = (busca, teste = false) =>
    http.get('/admin/cerimonial/busca', { params: comTeste({ busca }, teste) }).then((r) => r.data.data);

/** A ficha de um projeto: integrantes e quem já entrou. */
export const getFichaCerimonial = (projetoId, teste = false) =>
    http.get(`/admin/cerimonial/projetos/${projetoId}`, { params: comTeste({}, teste) }).then((r) => r.data);

/** Check-in de uma ou mais pessoas: `participantes` são chaves "A45"/"O12". */
export const registrarCheckin = (projetoId, participantes, teste = false) =>
    http.post(`/admin/cerimonial/projetos/${projetoId}/checkin`, { participantes }, { params: comTeste({}, teste) })
        .then((r) => r.data.data);

/** Desfaz o check-in de uma pessoa — justificativa obrigatória. */
export const desfazerCheckin = (projetoId, participante, justificativa, teste = false) =>
    http.post(
        `/admin/cerimonial/projetos/${projetoId}/desfazer`,
        { participante, justificativa },
        { params: comTeste({}, teste) },
    ).then((r) => r.data.data);

/** Os cards do painel — só os números (é o que o polling recarrega). */
export const getVisaoGeral = (teste = false) =>
    http.get('/admin/cerimonial/visao-geral', { params: comTeste({}, teste) }).then((r) => r.data);

/** A lista nominal de um card: quem chegou e quem falta. */
export const getDetalheCard = (card, teste = false) =>
    http.get('/admin/cerimonial/visao-geral/detalhe', { params: comTeste({ card }, teste) })
        .then((r) => r.data.data);

/** Um cartão por projeto premiado, com o ícone de cada integrante. */
export const getPremiados = (teste = false) =>
    http.get('/admin/cerimonial/premiados', { params: comTeste({}, teste) }).then((r) => r.data.data);

/** De quantos em quantos segundos o painel se atualiza (null desliga). */
export const definirAtualizacao = (segundos) =>
    http.patch('/admin/cerimonial/atualizacao', { segundos }).then((r) => r.data.data);
