import http from './http.js';

// Parametrização → Itens do credenciamento e da checagem (Sprint 181): a lista
// única que os dois balcões conferem. Cada chamada devolve a lista inteira.

export const getItensConferencia = () =>
    http.get('/admin/itens-conferencia').then((r) => r.data.data);

export const criarItemConferencia = (dados) =>
    http.post('/admin/itens-conferencia', dados).then((r) => r.data);

export const atualizarItemConferencia = (id, dados) =>
    http.patch(`/admin/itens-conferencia/${id}`, dados).then((r) => r.data);

export const excluirItemConferencia = (id) =>
    http.delete(`/admin/itens-conferencia/${id}`).then((r) => r.data);
