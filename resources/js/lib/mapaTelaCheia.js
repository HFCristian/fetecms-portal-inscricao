/**
 * Mapa do Evento em **tela cheia** (Sprints 198–200): as cores da faixa de
 * área, das três visualizações (credenciamento, checagem, avaliação) e o
 * resumo do painel lateral. Tudo puro — a tela só desenha.
 *
 * As cores seguem o guia de visualização do projeto, validadas por script:
 *
 * - **Área** (categórica, cor fixa por área — nunca pela posição na lista):
 *   as oito cores passam lado a lado, mas num mapa em que todas aparecem
 *   juntas algumas ficam próximas demais (vermelho × laranja). Por isso a
 *   faixa leva a **sigla escrita** e a legenda deixa **destacar uma área**: a
 *   cor nunca é a única pista.
 * - **Avaliações** (ordinal, um tom só, do claro ao escuro): azul 250/400/600,
 *   que passa nos testes de escala ordinal sobre o fundo claro.
 * - **Credenciado / checado** (estado): o verde de "bom", sempre com rótulo na
 *   legenda. Na checagem, o estande checado **sem ter passado pelo
 *   credenciamento** fica **laranja**: a conferência aconteceu, mas a equipe
 *   ainda deve o balcão, e o verde esconderia isso. O **credenciado que ainda
 *   não foi checado** fica **amarelo**: a equipe chegou e o estande espera a
 *   checagem — é a fila de quem confere, e o branco a misturaria com quem nem
 *   apareceu.
 * - **Saiu antes do horário** (Sprint 243): cinza-escuro em qualquer
 *   visualização. O estande está vazio, e o verde do credenciamento ou o azul
 *   das avaliações diriam que a equipe está lá.
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

const VERDE_OK = '#0ca30c';
const OK = { fill: VERDE_OK, stroke: '#0a7a0a', texto: '#ffffff' };
/** Checado sem credenciamento: laranja com texto escuro (branco não lê sobre ele). */
const ALERTA = { fill: '#f5871f', stroke: '#a4520a', texto: '#0b0b0b' };
/** Credenciado e ainda não checado: amarelo com texto escuro. */
const AGUARDANDO_CHECAGEM = { fill: '#f5d000', stroke: '#9c7c00', texto: '#0b0b0b' };
const SEM_PROJETO = { fill: '#f3f1f6', stroke: '#e6e0ee', texto: '#b9b2c4' };
/** A equipe foi embora antes do fim do turno (Sprint 243). */
const SAIU = { fill: '#5c5866', stroke: '#2e2b33', texto: '#ffffff' };
const PENDENTE = { fill: '#ffffff', stroke: '#b9b2c4', texto: '#52514e' };

/** Os passos do azul, do claro ao escuro (o 250 é o mais claro que ainda aparece no fundo). */
const AZUL = ['#86b6ef', '#6da7ec', '#5598e7', '#3987e5', '#2a78d6', '#256abf', '#1c5cab', '#184f95', '#104281'];
const AZUL_VALIDADO = ['#86b6ef', '#3987e5', '#184f95'];

/**
 * A escala das avaliações: um tom para cada quantidade de 1 até o máximo do
 * edital (0 é "pendente", sem cor). Até 3, os passos validados; acima disso,
 * espalhados pela mesma rampa.
 */
export function escalaAvaliacoes(maximo) {
    const m = Math.max(1, maximo || 1);
    if (m <= 3) return AZUL_VALIDADO.slice(0, m);

    return Array.from({ length: m }, (_, i) => AZUL[Math.round((i * (AZUL.length - 1)) / (m - 1))]);
}

export const ESCOPOS = [
    { value: 'credenciamento', label: 'Credenciamento' },
    { value: 'checagem', label: 'Checagem' },
    { value: 'avaliacao', label: 'Avaliação' },
];

/** Cor do estande na visualização escolhida. `linha` nula = estande sem projeto no turno. */
export function corNoEscopo(escopo, linha, maximo) {
    if (!linha) return SEM_PROJETO;
    if (linha.saida) return SAIU;

    if (escopo === 'credenciamento') return linha.credenciado ? OK : PENDENTE;

    if (escopo === 'checagem') {
        if (!linha.checado) return linha.credenciado ? AGUARDANDO_CHECAGEM : PENDENTE;
        return linha.credenciado ? OK : ALERTA;
    }

    const feitas = linha.avaliacoes ?? 0;
    if (feitas === 0) return PENDENTE;

    const escala = escalaAvaliacoes(maximo);
    const indice = Math.min(feitas, escala.length) - 1;
    const fill = escala[indice];

    // Do 400 para baixo (mais escuro), texto branco.
    return { fill, stroke: '#0d366b', texto: AZUL.indexOf(fill) >= 3 ? '#ffffff' : '#0b0b0b' };
}

/**
 * A legenda da visualização, com quantos estandes há em cada faixa. Quem saiu
 * antes do horário conta numa faixa própria, no fim — e só aparece quando há.
 */
export function legendaDoEscopo(escopo, todas, maximo) {
    const linhas = todas.filter((l) => !l.saida);
    const saiu = todas.length - linhas.length;
    const faixaSaiu = saiu > 0 ? [{ cor: SAIU.fill, borda: SAIU.stroke, label: 'Saiu antes do horário', total: saiu }] : [];

    return [...legendaDosPresentes(escopo, linhas, maximo), ...faixaSaiu];
}

function legendaDosPresentes(escopo, linhas, maximo) {
    if (escopo === 'credenciamento') {
        const ok = linhas.filter((l) => l.credenciado).length;

        return [
            { cor: OK.fill, borda: OK.stroke, label: 'Credenciado', total: ok },
            { cor: PENDENTE.fill, borda: PENDENTE.stroke, label: 'Pendente', total: linhas.length - ok },
        ];
    }

    if (escopo === 'checagem') {
        const ok = linhas.filter((l) => l.checado && l.credenciado).length;
        const semCredenciamento = linhas.filter((l) => l.checado && !l.credenciado).length;
        const aguardando = linhas.filter((l) => !l.checado && l.credenciado).length;

        return [
            { cor: OK.fill, borda: OK.stroke, label: 'Checado', total: ok },
            { cor: AGUARDANDO_CHECAGEM.fill, borda: AGUARDANDO_CHECAGEM.stroke, label: 'Credenciado, aguardando checagem', total: aguardando },
            { cor: ALERTA.fill, borda: ALERTA.stroke, label: 'Checado sem credenciamento', total: semCredenciamento },
            { cor: PENDENTE.fill, borda: PENDENTE.stroke, label: 'Pendente', total: linhas.length - ok - semCredenciamento - aguardando },
        ];
    }

    const escala = escalaAvaliacoes(maximo);
    const faixas = [{ cor: PENDENTE.fill, borda: PENDENTE.stroke, label: 'Nenhuma', total: 0 }]
        .concat(escala.map((cor, i) => ({
            cor,
            borda: '#0d366b',
            label: i === escala.length - 1 ? `${i + 1} ou mais` : String(i + 1),
            total: 0,
        })));

    for (const l of linhas) {
        faixas[Math.min(l.avaliacoes ?? 0, escala.length)].total += 1;
    }

    return faixas;
}

/**
 * O painel lateral (Sprint 200): projetos por quantidade de avaliações
 * recebidas — no geral e por área —, quantos de cada área já tiveram
 * avaliação da comissão especial e a barra de avaliações feitas sobre a meta
 * (projetos × avaliações por projeto). Na barra, o que passa da meta num
 * projeto não compensa a falta em outro.
 */
export function resumoDoPainel(linhas, maximo) {
    const m = Math.max(1, maximo || 1);
    const faixa = (l) => Math.min(l.avaliacoes ?? 0, m);
    const vazio = () => Array(m + 1).fill(0);

    const geral = vazio();
    const porArea = new Map();

    for (const l of linhas) {
        geral[faixa(l)] += 1;

        const chave = l.area ?? 'Sem área';
        if (!porArea.has(chave)) {
            porArea.set(chave, { area: chave, sigla: l.area_sigla ?? null, total: 0, faixas: vazio(), comComite: 0 });
        }
        const grupo = porArea.get(chave);
        grupo.total += 1;
        grupo.faixas[faixa(l)] += 1;
        if ((l.avaliacoes_comite ?? 0) > 0) grupo.comComite += 1;
    }

    const feitas = linhas.reduce((soma, l) => soma + faixa(l), 0);
    const meta = linhas.length * m;
    const areas = [...porArea.values()].sort((a, b) => a.area.localeCompare(b.area, 'pt-BR'));

    return {
        total: linhas.length,
        maximo: m,
        rotulos: Array.from({ length: m + 1 }, (_, i) => (i === m ? `${i}+` : String(i))),
        geral,
        areas,
        comComite: areas.reduce((soma, a) => soma + a.comComite, 0),
        progresso: { feitas, meta, pct: meta > 0 ? Math.round((feitas / meta) * 100) : 0 },
    };
}
