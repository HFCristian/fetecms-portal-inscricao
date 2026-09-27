/**
 * Idiomas em que o avaliador pode conduzir uma avaliação.
 *
 * Espelha `App\Support\Idiomas`: os códigos são ISO 639-1 e são eles que
 * viajam para o servidor — o rótulo é só o que a tela escreve. Fica num módulo
 * só porque três telas desenham a mesma lista (cadastro, perfil e a tabela do
 * admin), e uma delas fora de sincronia mandaria um código que o servidor
 * recusa.
 *
 * O perfil do avaliador usa as opções que vêm no payload (`idiomas_opcoes`),
 * não esta lista: lá o servidor já as manda junto dos valores marcados.
 */
export const IDIOMAS = [
    { value: 'pt', label: 'Português' },
    { value: 'es', label: 'Espanhol' },
    { value: 'en', label: 'Inglês' },
];

/** Rótulos dos códigos marcados, na ordem canônica. */
export const rotulosIdiomas = (codigos = []) =>
    IDIOMAS.filter((i) => codigos.includes(i.value)).map((i) => i.label).join(', ');
