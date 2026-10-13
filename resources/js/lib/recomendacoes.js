import http from './http.js';

/**
 * Recomendações do comitê especial para a premiação (Sprint 193), do lado da
 * organização: listar e decidir. Abrem nas abas Comitê especial e Avaliação
 * presencial. Aprovar só marca — anexar a credencial é outro passo.
 */

/**
 * `status`: 'pendente' | 'aprovada' | 'rejeitada' | null (todas).
 * `filtros` (Sprint 194): { q, area_id, categoria } — o recorte pelo projeto.
 */
export function getRecomendacoes(status = null, filtros = {}) {
    const params = {};
    if (status) params.status = status;
    if (filtros.q?.trim()) params.q = filtros.q.trim();
    if (filtros.area_id) params.area_id = filtros.area_id;
    if (filtros.categoria) params.categoria = filtros.categoria;

    return http.get('/admin/premiacao/recomendacoes', { params }).then((r) => r.data.data);
}

/** `status`: 'aprovada' | 'rejeitada'. */
export const decidirRecomendacao = (id, status) =>
    http.post(`/admin/premiacao/recomendacoes/${id}/decisao`, { status }).then((r) => r.data);
