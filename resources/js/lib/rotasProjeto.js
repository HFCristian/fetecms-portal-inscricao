/**
 * Onde vivem as telas de projeto.
 *
 * O admin reaproveita as MESMAS telas do orientador para terminar um rascunho
 * alheio ("Projetos em rascunho"), sob outro prefixo de URL — o componente é um
 * só, o que muda é para onde ele navega e como se chama o caminho de volta.
 */
export const baseProjeto = (modoAdmin) => (modoAdmin ? '/admin/projetos-rascunho' : '/projetos');

export const voltarLabel = (modoAdmin) => (modoAdmin ? 'Projetos em rascunho' : 'Meus projetos');
