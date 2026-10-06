import http from './http.js';

export const listarDocumentos = (projetoId) =>
    http.get(`/projetos/${projetoId}/documentos`).then((r) => r.data.data);

export const enviarDocumento = (projetoId, file, tipo) => {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('tipo', tipo);
    return http
        .post(`/projetos/${projetoId}/documentos`, fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        .then((r) => r.data.data);
};

export const removerDocumento = (docId) => http.delete(`/documentos/${docId}`);

/**
 * Baixa todos os documentos do projeto num ZIP (Sprint 167). O pedido vai por
 * blob — e não por um link direto — para a recusa do servidor (projeto sem
 * arquivo) virar mensagem na tela, e não uma página de JSON.
 */
export async function baixarDocumentosZip(projetoId) {
    let r;
    try {
        r = await http.get(`/projetos/${projetoId}/documentos/zip`, { responseType: 'blob' });
    } catch (e) {
        // Com responseType blob, o corpo do erro também chega como blob.
        const corpo = e?.response?.data;
        if (corpo instanceof Blob) {
            try {
                const json = JSON.parse(await corpo.text());
                throw new Error(Object.values(json.errors ?? {})[0]?.[0] || json.message || 'Não foi possível baixar os documentos.');
            } catch (interno) {
                if (interno instanceof SyntaxError) throw new Error('Não foi possível baixar os documentos.');
                throw interno;
            }
        }
        throw new Error('Não foi possível baixar os documentos.');
    }

    const nome = /filename="?([^";]+)"?/.exec(r.headers?.['content-disposition'] ?? '')?.[1] ?? `documentos-${projetoId}.zip`;
    const url = URL.createObjectURL(r.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = nome;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
