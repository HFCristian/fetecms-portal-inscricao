import http from './http.js';

/**
 * Recomendações do comitê especial para a premiação (Sprint 193), do lado da
 * organização: decidir. Desde a Sprint 201 elas chegam dentro do cartão de
 * cada credencial. Aprovar só marca — anexar a credencial é outro passo.
 */

/** `status`: 'aprovada' | 'rejeitada'. */
export const decidirRecomendacao = (id, status) =>
    http.post(`/admin/premiacao/recomendacoes/${id}/decisao`, { status }).then((r) => r.data);

/** O painel da premiação (Sprint 196): o que falta e o ranking por área. */
export const getPainelPremiacao = () => http.get('/admin/premiacao/painel').then((r) => r.data.data);

/** O recorte das recomendações nos cartões (Sprint 201): só o que foi preenchido. */
export function paramsDoRecorte(filtros = {}) {
    const params = {};
    if (filtros.q?.trim()) params.q = filtros.q.trim();
    if (filtros.area_id) params.area_id = filtros.area_id;
    if (filtros.categoria) params.categoria = filtros.categoria;
    return params;
}
