/**
 * Mapa do Evento em **tela cheia** (Sprint 198): as cores da faixa de área.
 * Tudo puro — a tela só desenha.
 *
 * As cores seguem o guia de visualização do projeto, validadas por script:
 *
 * - **Área** (categórica, cor fixa por área — nunca pela posição na lista):
 *   as oito cores passam lado a lado, mas num mapa em que todas aparecem
 *   juntas algumas ficam próximas demais (vermelho × laranja). Por isso a
 *   faixa leva a **sigla escrita** e a legenda deixa **destacar uma área**: a
 *   cor nunca é a única pista.
 */

/** Cor fixa de cada área, pela sigla da lista final. Área nova cai em "Outras". */
export const CORES_AREA = {
    EXA: '#2a78d6',
    ENG: '#eb6834',
    BIO: '#1baf7a',
    HUM: '#eda100',
    SOC: '#e87ba4',
    AGR: '#008300',
    LIN: '#6250d6',
    SAU: '#e34948',
};
export const COR_AREA_OUTRAS = '#898781';

/** Cores da faixa em que texto branco não lê bem (contraste < 3:1). */
const FAIXA_CLARA = new Set(['#1baf7a', '#eda100', '#e87ba4']);

export function corDaArea(sigla) {
    return CORES_AREA[sigla] ?? COR_AREA_OUTRAS;
}

export function textoSobreArea(cor) {
    return FAIXA_CLARA.has(cor) ? '#0b0b0b' : '#ffffff';
}
