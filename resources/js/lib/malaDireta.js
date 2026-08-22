import http from './http.js';

// Mala direta do admin: prévia do público, disparo e relatório de envio.

export const getOpcoesMala = () => http.get('/admin/mala-direta/opcoes').then((r) => r.data.data);

export const getMalas = (params = {}) =>
    http.get('/admin/mala-direta', { params }).then((r) => r.data);

// `criterio`: { publicos: [], destinatarios: [{ email, nome }], pagina, por_pagina }
export const getPreviaMala = (criterio) =>
    http.post('/admin/mala-direta/previa', criterio).then((r) => r.data);

export const exportarPreviaCsv = (criterio) =>
    baixar(http.post('/admin/mala-direta/previa/exportar', criterio, { responseType: 'blob' }), 'destinatarios.csv');

export const dispararMala = (payload) => http.post('/admin/mala-direta', payload).then((r) => r.data.data);

export const getMala = (id) => http.get(`/admin/mala-direta/${id}`).then((r) => r.data.data);

export const getMalaDestinatarios = (id, params = {}) =>
    http.get(`/admin/mala-direta/${id}/destinatarios`, { params }).then((r) => r.data);

export const exportarMalaCsv = (id) =>
    baixar(http.get(`/admin/mala-direta/${id}/exportar`, { responseType: 'blob' }), `mala-${id}-destinatarios.csv`);

export const reenviarFalhasMala = (id) =>
    http.post(`/admin/mala-direta/${id}/reenviar-falhas`).then((r) => r.data);

/** Dispara o download do blob devolvido pela API, respeitando o nome do header. */
async function baixar(requisicao, padrao) {
    const r = await requisicao;
    const nome = /filename="([^"]+)"/.exec(r.headers['content-disposition'] ?? '')?.[1] ?? padrao;
    const url = URL.createObjectURL(r.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = nome;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

const SEPARADORES = [';', ',', '\t'];

/** Separador mais frequente na linha (o Excel em pt_BR grava com ";"). */
function detectarSeparador(linha) {
    return SEPARADORES.reduce(
        (melhor, sep) => (linha.split(sep).length > linha.split(melhor).length ? sep : melhor),
        SEPARADORES[0],
    );
}

/** Quebra uma linha de CSV respeitando aspas duplas. */
function dividir(linha, sep) {
    const campos = [];
    let atual = '';
    let entreAspas = false;

    for (let i = 0; i < linha.length; i += 1) {
        const c = linha[i];
        if (c === '"') {
            if (entreAspas && linha[i + 1] === '"') { atual += '"'; i += 1; } else { entreAspas = !entreAspas; }
        } else if (c === sep && !entreAspas) {
            campos.push(atual);
            atual = '';
        } else {
            atual += c;
        }
    }
    campos.push(atual);

    return campos.map((c) => c.trim());
}

const semAcento = (texto) =>
    texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

const PARECE_EMAIL = /^[^\s@]+@[^\s@]+$/;

/**
 * Lê o .csv de destinatários. Espera as colunas `email` (obrigatória) e `nome`
 * (opcional) — colunas extras são ignoradas. Sem cabeçalho, assume a 1ª coluna
 * como e-mail e a 2ª como nome.
 *
 * @returns {{ destinatarios: Array<{email: string, nome: string}>, ignoradas: number }}
 */
export function parseCsvDestinatarios(texto) {
    const linhas = String(texto ?? '').split(/\r?\n/).filter((l) => l.trim() !== '');
    if (linhas.length === 0) return { destinatarios: [], ignoradas: 0 };

    const sep = detectarSeparador(linhas[0]);
    const cabecalho = dividir(linhas[0], sep).map(semAcento);
    let idxEmail = cabecalho.findIndex((c) => c === 'email' || c === 'e-mail');
    let idxNome = cabecalho.findIndex((c) => c === 'nome');
    let inicio = 1;

    if (idxEmail === -1) {
        // Sem cabeçalho reconhecível: 1ª coluna é o e-mail, 2ª (se houver) o nome.
        idxEmail = 0;
        idxNome = cabecalho.length > 1 ? 1 : -1;
        inicio = 0;
    }

    const destinatarios = [];
    let ignoradas = 0;

    for (let i = inicio; i < linhas.length; i += 1) {
        const campos = dividir(linhas[i], sep);
        const email = (campos[idxEmail] ?? '').trim();
        if (email === '') { ignoradas += 1; continue; }
        destinatarios.push({ email, nome: idxNome >= 0 ? (campos[idxNome] ?? '').trim() : '' });
    }

    return { destinatarios, ignoradas };
}

/**
 * Lê e-mails digitados à mão: um por linha ou separados por vírgula/ponto e
 * vírgula. Aceita também o formato "Nome <email@dominio>".
 *
 * @returns {Array<{email: string, nome: string}>}
 */
export function parseEmailsColados(texto) {
    return String(texto ?? '')
        .split(/[\n,;]+/)
        .map((parte) => parte.trim())
        .filter((parte) => parte !== '')
        .map((parte) => {
            const comNome = /^(.*?)<([^>]+)>$/.exec(parte);
            if (comNome) {
                return { email: comNome[2].trim(), nome: comNome[1].trim().replace(/^"|"$/g, '') };
            }
            return { email: parte, nome: '' };
        });
}

/** Junta listas de destinatários removendo repetidos (o 1º nome informado vence). */
export function mesclarDestinatarios(atuais, novos) {
    const porEmail = new Map();
    [...atuais, ...novos].forEach(({ email, nome }) => {
        const chave = String(email ?? '').trim().toLowerCase();
        if (chave === '') return;
        const existente = porEmail.get(chave);
        if (existente) {
            if (!existente.nome && nome) existente.nome = nome;
            return;
        }
        porEmail.set(chave, { email: String(email).trim(), nome: nome ?? '' });
    });

    return [...porEmail.values()];
}

/** Só para o aviso da tela: o backend é quem decide o que é inválido. */
export const emailParecaValido = (email) => PARECE_EMAIL.test(String(email ?? '').trim());
