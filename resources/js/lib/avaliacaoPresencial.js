import http from './http.js';

// Aba "Presencial" do avaliador: a intenção de avaliar no dia da feira e as
// orientações que a organização mostra a quem aceita. `teste` só tem efeito
// para a conta demo — é o modo que ignora as datas do evento.
const params = (teste) => ({ params: teste ? { teste: 1 } : {} });

export const getPresencial = (teste = false) =>
    http.get('/avaliador/presencial', params(teste)).then((r) => r.data.data);

export const responderPresencial = (presencial, teste = false) =>
    http.put('/avaliador/presencial', { presencial, teste: teste ? 1 : 0 })
        .then((r) => r.data);

// --- A avaliação no estande (durante o evento) -------------------------------

export const getPainelPresencial = (teste = false) =>
    http.get('/avaliador/presencial/avaliacoes', params(teste)).then((r) => r.data.data);

export const iniciarAvaliacaoPresencial = (projetoId, teste = false) =>
    http.post(`/avaliador/presencial/avaliacoes/projetos/${projetoId}`, { teste: teste ? 1 : 0 })
        .then((r) => r.data.data);

/** Regra do comitê (Sprint 234): pedir para avaliar fora das designações. */
export const pedirPermissaoPresencial = (projetoId, justificativa = '', teste = false) =>
    http.post(`/avaliador/presencial/avaliacoes/projetos/${projetoId}/permissao`, {
        justificativa: justificativa || null,
        teste: teste ? 1 : 0,
    }).then((r) => r.data);

export const getAvaliacaoPresencial = (id, teste = false) =>
    http.get(`/avaliador/presencial/avaliacoes/${id}`, params(teste)).then((r) => r.data.data);

export const salvarRascunhoPresencial = (id, dados, teste = false) =>
    http.post(`/avaliador/presencial/avaliacoes/${id}/rascunho`, { ...dados, teste: teste ? 1 : 0 })
        .then((r) => r.data);

export const concluirAvaliacaoPresencial = (id, dados, teste = false) =>
    http.post(`/avaliador/presencial/avaliacoes/${id}/concluir`, { ...dados, teste: teste ? 1 : 0 })
        .then((r) => r.data);

// --- Em lote, pela planilha (Sprint 239) -------------------------------------
// Modelo (os designados dele) → prévia linha a linha (nada é gravado) → envio
// das linhas válidas, que passam pelo mesmo fluxo do wizard.

/** Baixa a planilha com os projetos designados a ele. */
export async function baixarPlanilhaLote(teste = false) {
    const r = await http.get('/avaliador/presencial/lote/modelo', { ...params(teste), responseType: 'blob' });
    const url = URL.createObjectURL(r.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'avaliacoes-presenciais.xlsx';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

export const previaLotePresencial = (arquivo, teste = false) => {
    const form = new FormData();
    form.append('arquivo', arquivo);
    if (teste) form.append('teste', '1');

    return http.post('/avaliador/presencial/lote/previa', form).then((r) => r.data.data);
};

/** `linhas` são as válidas da prévia: { linha, projeto_id, respostas, comentario }. */
export const enviarLotePresencial = (linhas, teste = false) =>
    http.post('/avaliador/presencial/lote', { linhas, teste: teste ? 1 : 0 }).then((r) => r.data);
