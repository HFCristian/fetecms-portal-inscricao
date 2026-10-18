import http from './http.js';
import { paramsDoRecorte } from './recomendacoes.js';

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

// --- Designação do comitê (Sprint 137) --------------------------------------
//
// A aba Comitê designa projetos **só** para os avaliadores da comissão
// especial; a restrição é do servidor, não da tela.

export const getOpcoesDesignacaoComite = (filtros = {}) =>
    http.get('/admin/comite/designacoes/opcoes', {
        params: Object.fromEntries(Object.entries(filtros).filter(([, v]) => v)),
    }).then((r) => r.data.data);

export const designarPeloComite = (projetoIds, avaliadorIds) =>
    http.post('/admin/comite/designacoes', {
        projeto_ids: projetoIds,
        avaliador_ids: avaliadorIds,
    }).then((r) => r.data);

// --- Acompanhamento das avaliações do comitê ----------------------------------
//
// Cada avaliador da comissão com o que está avaliando e o mapa do evento só
// com as avaliações do comitê. Sem turno, o servidor escolhe o turno em foco.

export const getAcompanhamentoComite = (turno) =>
    http.get('/admin/comite/acompanhamento', { params: turno ? { turno } : {} }).then((r) => r.data.data);

/**
 * Remove de um avaliador da comissão as designações que ele ainda não abriu.
 * Os ids da tela dizem a origem ("online-12", "estande-5"); o servidor recebe
 * as duas listas separadas.
 */
export function retirarDesignacoesComite(avaliadorId, ids) {
    const da = (origem) => ids.filter((id) => String(id).startsWith(`${origem}-`)).map((id) => Number(String(id).slice(origem.length + 1)));

    return http.post(`/admin/comite/acompanhamento/avaliadores/${avaliadorId}/retirar`, {
        online_ids: da('online'),
        estande_ids: da('estande'),
    }).then((r) => r.data);
}

// --- Pedidos de permissão do comitê (Sprint 234) -----------------------------
//
// Com a regra "avaliar apenas projetos designados" ligada, o avaliador da
// comissão pede para avaliar fora das designações; a organização decide aqui.

/** `situacao`: 'pendente', 'decididos' ou vazio (todos). Devolve { data, meta }. */
export const getPermissoesComite = (situacao) =>
    http.get('/admin/comite/permissoes', { params: situacao ? { situacao } : {} }).then((r) => r.data);

export const decidirPermissaoComite = (id, decisao, resposta = null) =>
    http.post(`/admin/comite/permissoes/${id}/decisao`, { decisao, resposta }).then((r) => r.data);

// --- Premiação (Sprint 190) --------------------------------------------------
//
// A mesma base de Avaliação presencial → Credenciais e Prêmios: o comitê
// anexa e retira, mas não cadastra. Só vêm as credenciais ativas.

/** As credenciais ativas (data) e os finalistas que podem recebê-las (meta.candidatos). */
export const getPremiacaoComite = (filtros = {}) =>
    http.get('/admin/comite/premiacao', { params: paramsDoRecorte(filtros) }).then((r) => r.data);

export const premiarProjeto = (credencialId, projetoId, observacao = null) =>
    http.post(`/admin/comite/premiacao/${credencialId}/projetos`, { projeto_id: projetoId, observacao })
        .then((r) => r.data);

export const retirarPremiacao = (credencialId, projetoId) =>
    http.delete(`/admin/comite/premiacao/${credencialId}/projetos/${projetoId}`).then((r) => r.data);

/** A lista de premiação em TXT — o mesmo arquivo da Avaliação presencial. */
export async function baixarPremiacaoComite() {
    const r = await http.get('/admin/comite/premiacao/arquivo', { responseType: 'blob' });
    const url = URL.createObjectURL(r.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'lista-premiacao.txt';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}
