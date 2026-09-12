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

/** Move um projeto de turno à mão: sem justificativa, com registro. */
export const moverProjetoTurno = (projetoId, turno) =>
    http.patch('/admin/mapa/turnos/mover', { projeto_id: projetoId, turno }).then((r) => r.data);

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

export const getPlanta = () => http.get('/admin/mapa/planta').then((r) => r.data.data);

/** Grava o desenho como uma versão nova, que passa a ser a vigente. */
export const salvarPlanta = (layout) => http.post('/admin/mapa/planta', layout).then((r) => r.data);

/** Volta a uma versão anterior — que também nasce como versão nova. */
export const restaurarPlanta = (layoutId) =>
    http.post(`/admin/mapa/planta/${layoutId}/restaurar`).then((r) => r.data);
