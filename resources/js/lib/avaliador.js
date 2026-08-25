import http from './http.js';

// Perfil do avaliador: estatísticas do certificado e a própria área/subárea.
export const getPerfilAvaliador = () => http.get('/avaliador/perfil').then((r) => r.data.data);

// `classificacao`: { area_id, subarea_id | null }. Só passa fora do período de avaliação.
export const salvarClassificacaoAvaliador = (classificacao) =>
    http.put('/avaliador/perfil/classificacao', classificacao).then((r) => r.data.data);

// De onde o avaliador é (estado/cidade). Livre a qualquer momento: não entra na
// distribuição. Devolve { data, meta }.
export const atualizarLocalidadeAvaliador = (payload) =>
    http.put('/avaliador/perfil/localidade', payload).then((r) => r.data);
