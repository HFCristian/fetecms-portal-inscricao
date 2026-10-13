import http from './http.js';

// Titulação com a situação explícita: quem está CURSANDO a pós-graduação já
// pode avaliar, não precisa ter concluído (espelha AvaliadorProfile::TITULACOES).
export const TITULACOES = [
    'Especialização (em andamento)',
    'Especialização (concluída)',
    'Mestrado (em andamento)',
    'Mestrado (concluído)',
    'Doutorado (em andamento)',
    'Doutorado (concluído)',
];

// Perfil do avaliador: estatísticas do certificado e a própria área/subárea.
export const getPerfilAvaliador = () => http.get('/avaliador/perfil').then((r) => r.data.data);

// `classificacao`: { area_id, subarea_id | null }. Só passa fora do período de avaliação.
export const salvarClassificacaoAvaliador = (classificacao) =>
    http.put('/avaliador/perfil/classificacao', classificacao).then((r) => r.data.data);

// De onde o avaliador é (estado/cidade). Livre a qualquer momento: não entra na
// distribuição. Devolve { data, meta }.
export const atualizarLocalidadeAvaliador = (payload) =>
    http.put('/avaliador/perfil/localidade', payload).then((r) => r.data);

/** Tamanho de camiseta — opcional; `null` apaga a resposta. */
export const atualizarCamisetaAvaliador = (camiseta) =>
    http.put('/avaliador/perfil/camiseta', { camiseta }).then((r) => r.data);

/**
 * Idiomas em que ele pode avaliar (códigos 'pt', 'es', 'en'). Ao menos um — o
 * servidor recusa a lista vazia.
 */
export const atualizarIdiomasAvaliador = (idiomas) =>
    http.put('/avaliador/perfil/idiomas', { idiomas }).then((r) => r.data);
