/**
 * Comitê especial → Acompanhamento das avaliações: as cores do mapa do comitê,
 * a legenda, o resumo do dashboard e o filtro da lista de avaliadores. Tudo
 * puro — a tela só desenha.
 *
 * A cor do estande diz o que o **comitê** fez ali, nada mais: o que está
 * acontecendo agora manda (em avaliação), depois o que já foi feito (avaliado,
 * parcial quando a edição pede mais de uma avaliação da comissão por projeto),
 * depois o que só foi designado. Estande ainda não credenciado e checado tem a
 * borda tracejada — o comitê não começa ali.
 */

export const CORES_COMITE = {
    em_avaliacao: { fill: '#43157A', stroke: '#2a0058', texto: '#ffffff', label: 'Em avaliação agora' },
    avaliado: { fill: '#0ca30c', stroke: '#0a7a0a', texto: '#ffffff', label: 'Avaliado pelo comitê' },
    parcial: { fill: '#9fd8ae', stroke: '#2f8f4e', texto: '#0b3d1a', label: 'Avaliado em parte' },
    designado: { fill: '#e9ddff', stroke: '#6250d6', texto: '#2a0058', label: 'Designado, não iniciado' },
    pendente: { fill: '#ffffff', stroke: '#b9b2c4', texto: '#52514e', label: 'Sem avaliação do comitê' },
};

export const SEM_PROJETO = { fill: '#f3f1f6', stroke: '#e6e0ee', texto: '#b9b2c4', label: 'Sem projeto no turno' };

const ORDEM_LEGENDA = ['em_avaliacao', 'avaliado', 'parcial', 'designado', 'pendente'];

/** A chave de cor do estande: a situação, com "parcial" separado de "avaliado". */
export function chaveDoEstande(linha, vagas = 1) {
    if (!linha) return null;
    if (linha.situacao === 'avaliado' && linha.concluidas < Math.max(1, vagas)) return 'parcial';

    return linha.situacao;
}

export function corDoEstande(linha, vagas = 1) {
    const chave = chaveDoEstande(linha, vagas);

    return chave ? { ...CORES_COMITE[chave], tracejado: !linha.pronto } : SEM_PROJETO;
}

/** A legenda com quantos estandes há em cada cor. "Parcial" só existe com mais de uma vaga. */
export function legendaComite(linhas, vagas = 1) {
    const total = Object.fromEntries(ORDEM_LEGENDA.map((c) => [c, 0]));
    linhas.forEach((l) => { total[chaveDoEstande(l, vagas)] += 1; });

    return ORDEM_LEGENDA
        .filter((c) => c !== 'parcial' || vagas > 1)
        .map((c) => ({ chave: c, cor: CORES_COMITE[c].fill, borda: CORES_COMITE[c].stroke, label: CORES_COMITE[c].label, total: total[c] }));
}

/**
 * O dashboard do mapa: os projetos do turno pelo que o comitê fez neles (no
 * geral e por área), a barra de avaliações feitas sobre a meta (projetos ×
 * vagas da comissão — o que passa da meta num projeto não compensa a falta em
 * outro) e os avaliadores da comissão agora.
 */
export function resumoComite(linhas, avaliadores = [], vagas = 1) {
    const v = Math.max(1, vagas || 1);
    const conta = (lista, chave) => lista.filter((l) => chaveDoEstande(l, v) === chave).length;
    const porArea = new Map();

    for (const l of linhas) {
        const chave = l.area ?? 'Sem área';
        if (!porArea.has(chave)) porArea.set(chave, { area: chave, sigla: l.area_sigla ?? null, linhas: [] });
        porArea.get(chave).linhas.push(l);
    }

    const recorte = (lista) => ({
        total: lista.length,
        avaliados: lista.filter((l) => l.concluidas > 0).length,
        emAvaliacao: conta(lista, 'em_avaliacao'),
        designados: conta(lista, 'designado'),
        pendentes: conta(lista, 'pendente'),
        // Exclusivas, pela cor do estande: é o que a barra empilha.
        porSituacao: Object.fromEntries(['em_avaliacao', 'avaliado', 'designado', 'pendente']
            .map((s) => [s, lista.filter((l) => l.situacao === s).length])),
    });

    const feitas = linhas.reduce((soma, l) => soma + Math.min(l.concluidas ?? 0, v), 0);
    const meta = linhas.length * v;

    return {
        ...recorte(linhas),
        vagas: v,
        // O que a coordenação manda o comitê ver primeiro: pronto e ninguém foi.
        prontosSemComite: linhas.filter((l) => l.pronto && l.situacao === 'pendente').length,
        progresso: { feitas, meta, pct: meta > 0 ? Math.round((feitas / meta) * 100) : 0 },
        areas: [...porArea.values()]
            .map((a) => ({ area: a.area, sigla: a.sigla, ...recorte(a.linhas) }))
            .sort((a, b) => a.area.localeCompare(b.area, 'pt-BR')),
        avaliadores: {
            total: avaliadores.length,
            avaliando: avaliadores.filter((a) => a.situacao === 'avaliando').length,
            designados: avaliadores.filter((a) => a.situacao === 'designado').length,
            livres: avaliadores.filter((a) => a.situacao === 'livre').length,
            concluidas: avaliadores.reduce((soma, a) => soma + (a.concluidas?.length ?? 0), 0),
        },
        // Quem mais avaliou: o número, para a coordenação redistribuir esforço.
        destaques: [...avaliadores]
            .filter((a) => (a.concluidas?.length ?? 0) > 0)
            .sort((a, b) => b.concluidas.length - a.concluidas.length || a.nome.localeCompare(b.nome, 'pt-BR'))
            .slice(0, 5)
            .map((a) => ({ id: a.id, nome: a.nome, total: a.concluidas.length })),
    };
}

const ORDEM_SITUACAO = { avaliando: 0, designado: 1, livre: 2 };

const semAcento = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * A lista de avaliadores filtrada pela busca (nome, área ou qualquer projeto
 * dele: título, código, estande) e pela situação, com quem está avaliando
 * primeiro.
 */
export function filtrarAvaliadores(avaliadores, busca = '', situacao = '') {
    const termo = semAcento(busca.trim());

    const casa = (a) => {
        if (!termo) return true;
        const projetos = [...a.em_avaliacao, ...a.designadas, ...a.concluidas]
            .map((p) => `${p.titulo} ${p.codigo ?? ''} ${p.estande ? estande(p.estande) : ''}`);

        return semAcento([a.nome, a.area, ...projetos].join(' ')).includes(termo);
    };

    return avaliadores
        .filter((a) => (!situacao || a.situacao === situacao) && casa(a))
        .sort((a, b) => ORDEM_SITUACAO[a.situacao] - ORDEM_SITUACAO[b.situacao] || a.nome.localeCompare(b.nome, 'pt-BR'));
}

/** O número do estande como na planta: três dígitos. */
export function estande(n) {
    return String(n).padStart(3, '0');
}

/** "14:32" de um ISO 8601; vazio sem data. */
export function hora(iso) {
    if (!iso) return '';
    const d = new Date(iso);

    return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

/** "FET.AGR-001 · Estande 042 · Turno A (matutino)" — o que existir. */
export function localDoProjeto(p) {
    return [p.codigo, p.estande ? `Estande ${estande(p.estande)}` : null, p.turno_label].filter(Boolean).join(' · ');
}
