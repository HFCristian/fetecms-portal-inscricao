import http from './http.js';

/**
 * Avaliação presencial → **Intérpretes** (Sprints 214–215): cadastro, vínculos,
 * mapa de designação, exportação e a chegada por dia × turno.
 *
 * A ocorrência (`{ dia, turno }`) vai em toda chamada da chegada: a porta
 * escolhe em que dia e turno está registrando.
 */
const BASE = '/admin/presencial/interpretes';

export const getInterpretes = () => http.get(BASE).then((r) => r.data.data);

/** Finalistas com as pessoas de cada um, por título, código ou participante. */
export const buscarFinalistasInterprete = (q = '') =>
    http.get(`${BASE}/finalistas`, { params: q ? { q } : {} }).then((r) => r.data.data);

export const criarInterprete = (dados) => http.post(BASE, dados).then((r) => r.data);

export const atualizarInterprete = (id, dados) => http.put(`${BASE}/${id}`, dados).then((r) => r.data);

export const excluirInterprete = (id) => http.delete(`${BASE}/${id}`).then((r) => r.data);

/** Vincula o intérprete ao pedido de suporte (o projeto, ou o estudante do pedido). */
export const atenderPedidoInterprete = (interpreteId, pedidoId) =>
    http.post(`${BASE}/${interpreteId}/pedidos/${pedidoId}`).then((r) => r.data);

export const getMapaInterpretes = () => http.get(`${BASE}/mapa`).then((r) => r.data.data);

/** Designa (ou tira) o intérprete no estande de um turno — o vínculo ao projeto dali. */
export const designarInterprete = (id, { numero, turno, designado }) =>
    http.put(`${BASE}/${id}/estandes`, { numero, turno, designado }).then((r) => r.data.data);

async function baixar(url, nome, params = {}) {
    const resp = await http.get(url, { params, responseType: 'blob' });
    const link = document.createElement('a');
    const objeto = URL.createObjectURL(resp.data);

    link.href = objeto;
    link.download = nome;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objeto);
}

/** A lista com os códigos, em xlsx ou csv. */
export const exportarInterpretes = (formato) => baixar(`${BASE}/exportar/${formato}`, `interpretes.${formato}`);

/** As etiquetas de identificação em PDF — todas, ou as dos ids. */
export const baixarEtiquetasInterpretes = (ids = null) =>
    baixar(`${BASE}/etiquetas`, 'etiquetas-interpretes.pdf', ids ? { ids } : {});

// --- Chegada ------------------------------------------------------------------

export const getCheckinInterpretes = () => http.get(`${BASE}/checkin`).then((r) => r.data.data);

export const checkinInterpretePorCodigo = (codigo, { dia, turno }) =>
    http.post(`${BASE}/checkin/codigo`, { codigo, dia, turno }).then((r) => r.data.data);

export const buscarInterpretesCheckin = (q, { dia, turno }) =>
    http.get(`${BASE}/checkin/busca`, { params: { q, dia, turno } }).then((r) => r.data.data);

export const registrarChegadaInterprete = (id, { dia, turno }) =>
    http.post(`${BASE}/${id}/checkin`, { dia, turno }).then((r) => r.data.data);

export const desfazerChegadaInterprete = (id, { dia, turno }, justificativa) =>
    http.post(`${BASE}/${id}/checkin/desfazer`, { dia, turno, justificativa }).then((r) => r.data);

export const getAcompanhamentoInterpretes = ({ dia, turno }) =>
    http.get(`${BASE}/acompanhamento`, { params: { dia, turno } }).then((r) => r.data.data);

/** "2026-10-20|A" → { dia, turno } (a chave da ocorrência que o servidor manda). */
export function ocorrenciaDaChave(chave) {
    const [dia, turno] = (chave ?? '').split('|');
    return dia && turno ? { dia, turno } : null;
}

/** "Ana (Estudante) — FET.AGR-001 · Título", ou "… (projeto inteiro)": o vínculo em uma linha. */
export function descreverVinculo(v) {
    const projeto = `${v.codigo ? `${v.codigo} · ` : ''}${v.projeto_titulo}`;
    return v.pessoa_tipo ? `${v.pessoa_nome} (${v.pessoa_papel ?? 'participante'}) — ${projeto}` : `${projeto} (projeto inteiro)`;
}
