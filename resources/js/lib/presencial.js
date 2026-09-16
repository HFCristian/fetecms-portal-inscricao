import http from './http.js';

/**
 * Aba "Avaliação presencial" do admin: a checagem dos estandes no dia da feira,
 * o espelho dela e o catálogo do que se confere.
 *
 * `teste` só tem efeito para a conta demo — é o modo que ignora as datas do
 * evento e troca a lista final oficial pela de demonstração.
 */
const limpar = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v != null));

const com = (filtros, teste) => ({ params: { ...limpar(filtros), ...(teste ? { teste: 1 } : {}) } });

export const getConfigPresencial = (teste = false) =>
    http.get('/admin/presencial/config', com({}, teste)).then((r) => r.data.data);

export const definirInformacoesPresencial = (informacoes) =>
    http.patch('/admin/presencial/informacoes', { informacoes }).then((r) => r.data);

export const getChecagens = (filtros = {}, teste = false) =>
    http.get('/admin/presencial/checagem', com(filtros, teste)).then((r) => r.data);

export const getEspelhoChecagem = (filtros = {}, teste = false) =>
    http.get('/admin/presencial/checagem/espelho', com(filtros, teste)).then((r) => r.data);

export const getFichaEstande = (projetoId, teste = false) =>
    http.get(`/admin/presencial/checagem/${projetoId}`, com({}, teste)).then((r) => r.data.data);

export const registrarChecagem = (projetoId, dados, teste = false) =>
    http.post(`/admin/presencial/checagem/${projetoId}`, { ...dados, teste: teste ? 1 : 0 })
        .then((r) => r.data);

// Catálogo do que se confere em cada estande.
export const getItensChecagem = () =>
    http.get('/admin/presencial/itens').then((r) => r.data.data);

export const criarItemChecagem = (dados) =>
    http.post('/admin/presencial/itens', dados).then((r) => r.data.data);

export const atualizarItemChecagem = (id, dados) =>
    http.patch(`/admin/presencial/itens/${id}`, dados).then((r) => r.data.data);

export const excluirItemChecagem = (id) =>
    http.delete(`/admin/presencial/itens/${id}`).then((r) => r.data.data);

/** O PDF do termo de responsabilidade, servido pela rota autenticada. */
export const urlTermo = (documentoId) => `/api/v1/documentos/${documentoId}/preview`;

// --- Credenciais (vagas de premiação) ---------------------------------------

export const getCredenciais = () =>
    http.get('/admin/presencial/credenciais').then((r) => r.data);

export const criarCredencial = (dados) =>
    http.post('/admin/presencial/credenciais', dados).then((r) => r.data.data);

export const atualizarCredencial = (id, dados) =>
    http.patch(`/admin/presencial/credenciais/${id}`, dados).then((r) => r.data.data);

export const excluirCredencial = (id) =>
    http.delete(`/admin/presencial/credenciais/${id}`).then((r) => r.data.data);

export const atribuirCredencial = (id, projetoId, observacao = null) =>
    http.post(`/admin/presencial/credenciais/${id}/projetos`, { projeto_id: projetoId, observacao })
        .then((r) => r.data);

export const retirarCredencial = (id, projetoId) =>
    http.delete(`/admin/presencial/credenciais/${id}/projetos/${projetoId}`).then((r) => r.data);

export const getPremiacao = () =>
    http.get('/admin/presencial/credenciais/premiacao').then((r) => r.data.data);

/** Baixa a lista de premiação em TXT (a que se lê na cerimônia). */
export async function baixarPremiacao() {
    const r = await http.get('/admin/presencial/credenciais/premiacao/arquivo', { responseType: 'blob' });
    const url = URL.createObjectURL(r.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'lista-premiacao.txt';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}
