import http from './http.js';

// Aba "Documentos" do orientador: o termo de responsabilidade dos projetos
// finalistas. `teste` só tem efeito para a conta demo — é o modo que ignora as
// datas do evento e usa a lista final de demonstração.
const params = (teste) => ({ params: teste ? { teste: 1 } : {} });

export const getDocumentosPresenciais = (teste = false) =>
    http.get('/documentos-presenciais', params(teste)).then((r) => r.data.data);

/** Envia (ou substitui) o termo de responsabilidade do projeto. */
export function enviarTermo(projetoId, file, teste = false) {
    const form = new FormData();
    form.append('file', file);
    if (teste) form.append('teste', '1');

    return http.post(`/documentos-presenciais/projetos/${projetoId}/termo`, form)
        .then((r) => r.data);
}

export const removerTermo = (projetoId, teste = false) =>
    http.delete(`/documentos-presenciais/projetos/${projetoId}/termo`, {
        data: teste ? { teste: 1 } : {},
    }).then((r) => r.data);
