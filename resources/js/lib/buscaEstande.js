/**
 * Busca da tela de avaliação presencial do avaliador (Sprint 235): pelo
 * **título** do projeto ou pelo **número do estande** — "42", "042" ou
 * "estande 42". Sem acento e sem caixa.
 */

const semAcento = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** O número do estande de um item (`local.estande` ou `estande`). */
const estandeDe = (p) => p?.local?.estande ?? p?.estande ?? null;

export function casaBusca(p, busca) {
    const termo = semAcento(busca).trim();
    if (!termo) return true;

    const numero = termo.match(/^(?:estande\s*)?0*(\d{1,4})$/);
    if (numero && Number(estandeDe(p)) === Number(numero[1])) return true;

    return semAcento(p.titulo).includes(termo);
}

export function filtrarPorBusca(lista, busca) {
    return (lista ?? []).filter((p) => casaBusca(p, busca));
}
