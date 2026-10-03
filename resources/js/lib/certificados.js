import http from './http.js';

// Aba "Certificados" (Sprint 163): as planilhas para emissão e o relatório
// nominal de cada avaliador. O nome do arquivo vem do servidor.

export const getOpcoesCertificados = () => http.get('/admin/certificados/opcoes').then((r) => r.data.data);

export const getAvaliadoresCertificado = (fase = 'todas', q = '') =>
    http.get('/admin/certificados/avaliadores', { params: { fase, ...(q ? { q } : {}) } }).then((r) => r.data.data);

export const getProjetosDoAvaliador = (id) =>
    http.get(`/admin/certificados/avaliadores/${id}/projetos`).then((r) => r.data.data);

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

export const baixarAvaliadoresCertificado = (fase, formato) =>
    baixar('/admin/certificados/avaliadores/exportar', { fase, formato }, `avaliadores.${formato}`);

export const baixarParticipantesCertificado = (grupos, escopo, formato) =>
    baixar('/admin/certificados/participantes/exportar', { grupos, escopo, formato }, `participantes.${formato}`);

export const baixarAvaliacoesNominais = (fase, formato) =>
    baixar('/admin/certificados/avaliacoes/exportar', { fase, formato }, `projetos-avaliados.${formato}`);

export const baixarDeclaracaoAvaliador = (id) =>
    baixar(`/admin/certificados/avaliadores/${id}/declaracao`, {}, 'projetos-avaliados.pdf');
