import http from './http.js';

/**
 * Aba **Mapa do Evento**: turnos de apresentação, estandes e a planta do
 * ginásio.
 *
 * O painel e as ações devolvem sempre a lista inteira em `data` — gerar de novo
 * troca a divisão dos dois turnos de uma vez, então atualizar só metade da tela
 * deixaria a outra metade mentindo.
 */
export const getTurnos = () => http.get('/admin/mapa/turnos').then((r) => r.data.data);

/** Busca finalistas por título da inscrição **ou** por nome de participante. */
export const buscarFinalistas = (q = '') =>
    http.get('/admin/mapa/turnos/opcoes', { params: q ? { q } : {} }).then((r) => r.data.data);

/** Guarda capacidades e regras sem gerar nada (o rascunho da configuração). */
export const salvarConfigTurnos = (config) =>
    http.put('/admin/mapa/turnos/config', config).then((r) => r.data);

/** Gera — ou regera — a divisão. Só a última lista vale. */
export const gerarTurnos = (config) => http.post('/admin/mapa/turnos/gerar', config).then((r) => r.data);

/** Baixa a lista em txt, csv ou pdf pelo mesmo endpoint. */
export async function exportarTurnos(formato) {
    const resp = await http.get(`/admin/mapa/turnos/exportar/${formato}`, { responseType: 'blob' });
    const url = URL.createObjectURL(resp.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = `turnos-apresentacao.${formato}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

// --- Estandes dos projetos ------------------------------------------------

export const getEstandes = () => http.get('/admin/mapa/estandes').then((r) => r.data.data);

/** Guarda as faixas por categoria sem distribuir nada. */
export const salvarConfigEstandes = (config) =>
    http.put('/admin/mapa/estandes/config', config).then((r) => r.data);

/** Distribui os projetos pelos estandes — duas listas, uma por turno. */
export const gerarEstandes = (config) => http.post('/admin/mapa/estandes/gerar', config).then((r) => r.data);

/** Move um projeto de estande; ocupado, os dois trocam de lugar. */
export const moverProjetoEstande = (projetoId, numero) =>
    http.patch('/admin/mapa/estandes/mover', { projeto_id: projetoId, numero }).then((r) => r.data);

export async function exportarEstandes(formato) {
    const resp = await http.get(`/admin/mapa/estandes/exportar/${formato}`, { responseType: 'blob' });
    const url = URL.createObjectURL(resp.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = `estandes-projetos.${formato}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

// --- A planta do ginásio ---------------------------------------------------

export const getPlanta = () => http.get('/admin/mapa/planta').then((r) => r.data);

/** Grava o desenho como uma versão nova, que passa a ser a vigente. */
export const salvarPlanta = (layout) => http.post('/admin/mapa/planta', layout).then((r) => r.data);

/** Volta a uma versão anterior — que também nasce como versão nova. */
export const restaurarPlanta = (layoutId) =>
    http.post(`/admin/mapa/planta/${layoutId}/restaurar`).then((r) => r.data);

/**
 * A cor de cada estande num turno, como estava no fim do dia escolhido. É o
 * que a tela recarrega sozinha durante o evento — leve de propósito: a planta
 * e as versões não vêm junto.
 */
export const getSituacaoPlanta = (params = {}) =>
    http.get('/admin/mapa/planta/situacao', { params }).then((r) => r.data.data);

/** A lista filtrada por credenciamento, checagem ou número de avaliações. */
export const getListaSituacao = (params = {}) =>
    http.get('/admin/mapa/planta/lista', { params }).then((r) => r.data.data);

/** A mesma lista em arquivo (txt, csv ou pdf). */
export async function baixarListaSituacao(formato, params = {}) {
    const resp = await http.get(`/admin/mapa/planta/lista/${formato}`, { params, responseType: 'blob' });
    const url = URL.createObjectURL(resp.data);
    const link = document.createElement('a');

    link.href = url;
    link.download = `mapa-situacao.${formato}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

// --- Avaliações por projeto (Sprint 248) ------------------------------------

/**
 * Quais avaliações faltam em cada projeto do turno, comuns e do comitê: a
 * planta, as categorias (com a contagem e a cor de cada uma) e as linhas.
 */
export const getAvaliacoesMapa = (params = {}) =>
    http.get('/admin/mapa/avaliacoes', { params }).then((r) => r.data);

/**
 * O recorte dos filtros da tela: as categorias marcadas (nenhuma = todas) e,
 * se pedido, só quem tem avaliação em andamento. O servidor aplica a mesma
 * regra na exportação.
 */
export function filtrarAvaliacoes(linhas, { categorias = [], emAndamento = false } = {}) {
    const marcadas = new Set(categorias);

    return linhas.filter((l) => (marcadas.size === 0 || marcadas.has(l.situacao))
        && (!emAndamento || l.em_andamento > 0));
}

/** A lista ou o mapa em PDF, no recorte dos filtros. */
export async function baixarAvaliacoesMapa(tipo, { dia, turno, categorias = [], emAndamento = false } = {}) {
    const resp = await http.get(`/admin/mapa/avaliacoes/exportar/${tipo}`, {
        params: { dia: dia || undefined, turno, categorias, em_andamento: emAndamento ? 1 : 0 },
        responseType: 'blob',
    });
    const url = URL.createObjectURL(resp.data);
    const link = document.createElement('a');

    link.href = url;
    link.download = `avaliacoes-${tipo}.pdf`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

// --- Remanejar sem refazer (Sprints 212–213) -------------------------------

/**
 * O que está fora do lugar (finalista sem turno, sem estande, fora da lista) e
 * o que o diálogo de destino precisa: números da planta, ocupação e capacidade.
 */
export const getRemanejamento = () => http.get('/admin/mapa/remanejamento').then((r) => r.data.data);

/**
 * Põe um projeto num lugar (turno + estande). Com o estande ocupado, `modo`
 * diz o que acontece com o ocupante: `trocar`, `empurrar` ou `final`. Devolve
 * `{ data: { turnos, remanejamento, movimentos, avisos }, meta: { message } }`.
 */
export const alocarNoMapa = ({ projetoId, turno, numero = null, modo = null }) =>
    http.post('/admin/mapa/remanejamento/alocar', {
        projeto_id: projetoId,
        turno,
        ...(numero ? { numero: Number(numero) } : {}),
        ...(modo ? { modo } : {}),
    }).then((r) => r.data);

/** Tira o projeto do mapa (turno e estande) — quem saiu da lista final. */
export const retirarDoMapa = (projetoId) =>
    http.post('/admin/mapa/remanejamento/retirar', { projeto_id: projetoId }).then((r) => r.data);

// --- Saída antes do horário (Sprint 243) ------------------------------------

/**
 * A equipe chegou e foi embora antes do fim do turno. `saiuEm` é
 * "aaaa-mm-ddThh:mm" (hora de Campo Grande); em branco, o servidor usa agora.
 */
export const marcarSaida = ({ projetoId, saiuEm = null, observacao = '' }) =>
    http.post('/admin/mapa/saidas', {
        projeto_id: projetoId,
        saiu_em: saiuEm || null,
        observacao: observacao.trim() || null,
    }).then((r) => r.data);

/** Desfaz a marcação: a equipe voltou, ou era outro projeto. */
export const desfazerSaida = (id) =>
    http.delete(`/admin/mapa/saidas/${id}`).then((r) => r.data);
