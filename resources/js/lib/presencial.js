import http from './http.js';
import { paramsDoRecorte } from './recomendacoes.js';

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

/** O PDF do termo de responsabilidade, servido pela rota autenticada. */
export const urlTermo = (documentoId) => `/api/v1/documentos/${documentoId}/preview`;

// --- Cadastro de avaliadores do evento presencial (Sprint 191) --------------
// A senha vem em `meta.senha`, uma única vez: o portal guarda só o hash.

export const getAvaliadoresCadastrados = (q = '') =>
    http.get('/admin/presencial/avaliadores-cadastrados', { params: q ? { q } : {} }).then((r) => r.data.data);

export const cadastrarAvaliadorPresencial = (dados) =>
    http.post('/admin/presencial/avaliadores-cadastrados', dados).then((r) => r.data);

export const novaSenhaAvaliadorPresencial = (id) =>
    http.post(`/admin/presencial/avaliadores-cadastrados/${id}/senha`).then((r) => r.data);

// --- Validação de avaliadores (Sprint 223) -----------------------------------
// Liberar vale para o evento inteiro; `filtros` ({ busca, situacao }) volta na
// resposta já aplicado, para a tela não piscar.

export const getValidacaoPresencial = (filtros = {}, teste = false) =>
    http.get('/admin/presencial/validacao', com(filtros, teste)).then((r) => r.data.data);

export const liberarAvaliadoresPresencial = (avaliadorIds, filtros = {}, teste = false) =>
    http.post('/admin/presencial/validacao/liberar', {
        ...limpar(filtros), avaliador_ids: avaliadorIds, teste: teste ? 1 : 0,
    }).then((r) => r.data);

export const revogarLiberacaoPresencial = (avaliadorId, filtros = {}, teste = false) =>
    http.delete(`/admin/presencial/validacao/${avaliadorId}`, com(filtros, teste)).then((r) => r.data);

// --- Credenciais e prêmios (o que a feira tem para dar) ---------------------

/** `filtros` (Sprint 201): o recorte das recomendações do comitê nos cartões. */
export const getCredenciais = (filtros = {}) =>
    http.get('/admin/presencial/credenciais', { params: paramsDoRecorte(filtros) }).then((r) => r.data);

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

// --- Distribuição presencial por turno (Sprints 169–170) --------------------
// `ocorrencia` é { dia, turno }: o par dia × turno em que a tela está.

const corpo = (dados, teste) => ({ ...dados, teste: teste ? 1 : 0 });

export const getDistribuicaoPresencial = (filtros = {}, teste = false) =>
    http.get('/admin/presencial/distribuicao', com(filtros, teste)).then((r) => r.data.data);

export const salvarDistribuicaoPresencial = (dados, ocorrencia = {}, teste = false) =>
    http.patch('/admin/presencial/distribuicao', corpo({ ...dados, ...ocorrencia }, teste)).then((r) => r.data);

export const distribuirPresencial = (ocorrencia, teste = false) =>
    http.post('/admin/presencial/distribuicao/distribuir', corpo(ocorrencia, teste)).then((r) => r.data);

export const redistribuirPresencial = (ocorrencia, teste = false) =>
    http.post('/admin/presencial/distribuicao/redistribuir', corpo(ocorrencia, teste)).then((r) => r.data);

export const designarPresencial = (ocorrencia, projetoIds, avaliadorIds, teste = false) =>
    http.post('/admin/presencial/avaliacoes/designar', corpo({
        ...ocorrencia, projeto_ids: projetoIds, avaliador_ids: avaliadorIds,
    }, teste)).then((r) => r.data);

export const retirarDesignacaoPresencial = (id, ocorrencia = {}, teste = false) =>
    http.delete(`/admin/presencial/avaliacoes/${id}`, com(ocorrencia, teste)).then((r) => r.data);

// --- Ranking presencial (Sprint 222) -----------------------------------------
// `filtros` é { area_id, categoria }; a média vem pronta do servidor, com o
// peso da comissão especial.

export const getRankingPresencial = (filtros = {}, teste = false) =>
    http.get('/admin/presencial/ranking', com(filtros, teste)).then((r) => r.data.data);
