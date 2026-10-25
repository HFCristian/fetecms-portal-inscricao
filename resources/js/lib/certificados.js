import http from './http.js';

// Aba "Certificados" (Sprints 259–260): o assistente da lista de certificados,
// a conferência de nomes e a lista gerada. O nome do arquivo vem do servidor.

const base = '/admin/certificados';

export const getOpcoesCertificados = () => http.get(`${base}/opcoes`).then((r) => r.data.data);

export const getListasCertificados = () => http.get(`${base}/listas`).then((r) => r.data.data);

export const getListaCertificados = (id) => http.get(`${base}/listas/${id}`).then((r) => r.data.data);

/** Cria (sem id) ou grava a lista: nome, passo e configuração do assistente. */
export const salvarListaCertificados = (id, dados) =>
    (id ? http.put(`${base}/listas/${id}`, dados) : http.post(`${base}/listas`, dados)).then((r) => r.data.data);

export const getResumoCertificados = (id) => http.get(`${base}/listas/${id}/resumo`).then((r) => r.data.data);

export const getPessoasCategoria = (id, categoria) =>
    http.get(`${base}/listas/${id}/categorias/${categoria}`).then((r) => r.data.data);

/** `nomes`: chave da pessoa → nome corrigido (vazio desfaz). `originais`: o nome do cadastro. */
export const salvarNomesCertificados = (id, nomes, originais = {}) =>
    http.put(`${base}/listas/${id}/nomes`, { nomes, originais }).then((r) => r.data.data);

export const gerarListaCertificados = (id) => http.post(`${base}/listas/${id}/gerar`).then((r) => r.data.data);

export const getLinhasCertificados = (id, { categoria = '', q = '', page = 1 } = {}) =>
    http.get(`${base}/listas/${id}/linhas`, {
        params: { page, ...(categoria ? { categoria } : {}), ...(q ? { q } : {}) },
    }).then((r) => r.data);

export const getProjetosDoAvaliador = (id) =>
    http.get(`${base}/avaliadores/${id}/projetos`).then((r) => r.data.data);

async function baixar(url, params = {}, padrao = 'arquivo') {
    const resp = await http.get(url, { params, responseType: 'blob' });
    const nome = /filename="([^"]+)"/.exec(resp.headers?.['content-disposition'] ?? '')?.[1] ?? padrao;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(resp.data);
    link.download = nome;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(link.href);
}

export const baixarListaCertificados = (id, formato, categoria = '') =>
    baixar(`${base}/listas/${id}/exportar/${formato}`, categoria ? { categoria } : {}, `certificados.${formato}`);

export const baixarDeclaracaoAvaliador = (id) =>
    baixar(`${base}/avaliadores/${id}/declaracao`, {}, 'projetos-avaliados.pdf');

/** Minutos em "2h30" / "8h". */
export function duracao(minutos) {
    const m = Number(minutos) || 0;
    const h = Math.floor(m / 60);
    const resto = m % 60;
    return resto === 0 ? `${h}h` : `${h}h${String(resto).padStart(2, '0')}`;
}

/** Horas inteiras, para cima — o número que vai para o SICERT. */
export const horasDoArquivo = (minutos) => Math.ceil((Number(minutos) || 0) / 60);

/** "2026-10-20" em "20/10/2026". */
export function dataBr(iso) {
    if (!iso) return null;
    const [a, m, d] = iso.slice(0, 10).split('-');
    return `${d}/${m}/${a}`;
}
