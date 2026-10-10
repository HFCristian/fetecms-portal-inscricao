import http from './http.js';

// Aba "Documentos" do orientador: os itens que a organização quer receber dos
// finalistas antes do evento (Sprint 183). `teste` só tem efeito para a conta
// demo — é o modo que ignora as datas do evento e usa a lista final de
// demonstração.
const params = (teste) => ({ params: teste ? { teste: 1 } : {} });

export const getDocumentosPresenciais = (teste = false) =>
    http.get('/documentos-presenciais', params(teste)).then((r) => r.data.data);

/**
 * Envia um arquivo para um item. No item de pessoa, `pessoas` são as chaves
 * "papel:id" de quem o arquivo cobre. Devolve a seção do item atualizada.
 */
export function enviarArquivoItem(projetoId, itemId, file, pessoas = [], teste = false) {
    const form = new FormData();
    form.append('file', file);
    pessoas.forEach((p) => form.append('pessoas[]', p));
    if (teste) form.append('teste', '1');

    return http.post(`/documentos-presenciais/projetos/${projetoId}/itens/${itemId}`, form)
        .then((r) => r.data);
}

/** Troca para quem um arquivo vale. */
export const definirPessoasArquivo = (documentoId, pessoas, teste = false) =>
    http.put(`/documentos-presenciais/arquivos/${documentoId}`, {
        pessoas,
        ...(teste ? { teste: 1 } : {}),
    }).then((r) => r.data);

export const removerArquivo = (documentoId, teste = false) =>
    http.delete(`/documentos-presenciais/arquivos/${documentoId}`, {
        data: teste ? { teste: 1 } : {},
    }).then((r) => r.data);
