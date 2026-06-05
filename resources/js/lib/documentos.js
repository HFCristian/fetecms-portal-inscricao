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
