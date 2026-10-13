import http from './http.js';

/**
 * Recomendações do comitê especial para a premiação (Sprint 193), do lado da
 * organização: listar e decidir. Abrem nas abas Comitê especial e Avaliação
 * presencial. Aprovar só marca — anexar a credencial é outro passo.
 */

/** `status`: 'pendente' | 'aprovada' | 'rejeitada' | null (todas). */
export const getRecomendacoes = (status = null) =>
    http.get('/admin/premiacao/recomendacoes', { params: status ? { status } : {} }).then((r) => r.data.data);

/** `status`: 'aprovada' | 'rejeitada'. */
export const decidirRecomendacao = (id, status) =>
    http.post(`/admin/premiacao/recomendacoes/${id}/decisao`, { status }).then((r) => r.data);
