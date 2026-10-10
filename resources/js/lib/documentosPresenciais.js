import http from './http.js';

// Aba "Documentos" do orientador: o termo de responsabilidade e o termo de uso
// de imagem dos projetos finalistas. `teste` só tem efeito para a conta demo — é o modo que ignora as
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

/**
 * Envia um arquivo do termo de uso de imagem (Sprint 174), valendo para os
 * estudantes marcados. Devolve a situação do termo de imagem do projeto.
 */
export function enviarTermoImagem(projetoId, file, alunoIds, teste = false) {
    const form = new FormData();
    form.append('file', file);
    alunoIds.forEach((id) => form.append('alunos[]', String(id)));
    if (teste) form.append('teste', '1');

    return http.post(`/documentos-presenciais/projetos/${projetoId}/termo-imagem`, form)
        .then((r) => r.data);
}

/** Troca para quais estudantes um arquivo do termo de imagem vale. */
export const definirAlunosTermoImagem = (documentoId, alunoIds, teste = false) =>
    http.put(`/documentos-presenciais/termo-imagem/${documentoId}`, {
        alunos: alunoIds,
        ...(teste ? { teste: 1 } : {}),
    }).then((r) => r.data);

export const removerTermoImagem = (documentoId, teste = false) =>
    http.delete(`/documentos-presenciais/termo-imagem/${documentoId}`, {
        data: teste ? { teste: 1 } : {},
    }).then((r) => r.data);
