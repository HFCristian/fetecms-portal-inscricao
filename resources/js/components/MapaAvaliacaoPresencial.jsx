import { useMemo, useState } from 'react';

/** Tamanho de uma célula da grade, em unidades do viewBox (o mesmo da planta). */
const CELULA = 10;
const MARGEM = 6;

const TURNOS = [
    { value: 'A', label: 'Turno A (matutino)' },
    { value: 'B', label: 'Turno B (vespertino)' },
];

const rotulo = (n) => String(n).padStart(3, '0');

/** A cor e a frase de cada situação — a mesma legenda embaixo do mapa. */
const SITUACOES = {
    disponivel: { fill: '#d4f0dc', stroke: '#006e1f', texto: '#004d15', legenda: 'Disponível — clique para iniciar' },
    comigo: { fill: '#43157A', stroke: '#2a0058', texto: '#ffffff', legenda: 'Com você' },
    avaliado: { fill: '#006e1f', stroke: '#004d15', texto: '#ffffff', legenda: 'Você já avaliou' },
    completo: { fill: '#e6e0ee', stroke: '#cfc6db', texto: '#6b6478', legenda: 'Sem vaga para você' },
    nao_pronto: { fill: '#ffffff', stroke: '#cfc6db', texto: '#9a93a6', legenda: 'Ainda não credenciado e checado', tracejado: true },
    fora_do_turno: { fill: '#ffffff', stroke: '#cfc6db', texto: '#2a0058', legenda: 'Fora do turno em andamento' },
    nao_liberado: { fill: '#ffffff', stroke: '#cfc6db', texto: '#2a0058', legenda: 'Aguardando a sua liberação', semLegenda: true },
};

const VAZIO = { fill: '#f3f1f6', stroke: '#e6e0ee', texto: '#9a93a6' };

/** O que dizer ao clicar num estande em que não há o que iniciar. */
function motivo(projeto, maximo) {
    switch (projeto.situacao) {
        case 'completo':
            return `já recebeu as ${maximo} avaliações presenciais (ou a vaga que caberia a você).`;
        case 'nao_pronto':
            return 'ainda não foi credenciado e checado — volte daqui a pouco.';
        case 'fora_do_turno':
            return 'apresenta fora do turno em andamento: só dá para iniciar durante o turno do projeto.';
        case 'nao_liberado':
            return 'aguarda a sua liberação: a organização precisa liberar você para avaliar.';
        default:
            return '';
    }
}

/**
 * O mapa do evento na aba do avaliador (Sprint 221): o caminho da avaliação
 * **sem designação**. Ele escolhe o turno, clica no estande e inicia — ou
 * continua/relê o que já é dele. A cor diz o que dá para fazer em cada estande,
 * e quem decide é o servidor (`situacao`): o mapa só desenha.
 *
 * O estande que não está na planta (o ensaio usa 901–903) aparece embaixo,
 * para continuar alcançável.
 */
export default function MapaAvaliacaoPresencial({ mapa, onIniciar, onAbrir }) {
    const [turno, setTurno] = useState(mapa.turno_trabalho ?? 'A');
    const [aviso, setAviso] = useState('');

    const layout = mapa.layout ?? [];
    const estandes = mapa.estandes ?? {};
    const fora = (mapa.fora_da_planta ?? []).filter((p) => p.turno === turno);

    const caixa = useMemo(() => (layout.length === 0
        ? { largura: 10, altura: 10 }
        : { largura: Math.max(...layout.map((e) => e.x)) + 1, altura: Math.max(...layout.map((e) => e.y)) + 1 }), [layout]);

    function clicar(numero, projeto) {
        setAviso('');

        if (!projeto) {
            setAviso(`O estande ${rotulo(numero)} está vazio no ${turno === 'A' ? 'turno A' : 'turno B'}.`);
            return;
        }

        if (projeto.situacao === 'disponivel') {
            onIniciar({ id: projeto.projeto_id, titulo: projeto.titulo, local: { estande: numero, turno_label: TURNOS.find((t) => t.value === turno)?.label } });
            return;
        }

        if (projeto.avaliacao_id) {
            onAbrir(projeto.avaliacao_id);
            return;
        }

        setAviso(`Estande ${rotulo(numero)} · “${projeto.titulo}” ${motivo(projeto, mapa.max_por_projeto)}`);
    }

    const descricao = (numero, projeto) => (projeto
        ? `Estande ${rotulo(numero)} · ${projeto.titulo} · ${SITUACOES[projeto.situacao]?.legenda ?? ''}`
        : `Estande ${rotulo(numero)} · vazio`);

    return (
        <div>
            <div className="flex flex-wrap items-center gap-2 mb-3" role="radiogroup" aria-label="Turno do mapa">
                {TURNOS.map((t) => (
                    <button
                        key={t.value}
                        type="button"
                        role="radio"
                        aria-checked={turno === t.value}
                        onClick={() => { setTurno(t.value); setAviso(''); }}
                        className={`px-3 py-1 rounded-full text-sm font-semibold border ${
                            turno === t.value
                                ? 'bg-primary-container text-on-primary border-primary-container'
                                : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                        }`}
                    >
                        {t.label}
                        {mapa.turno_trabalho === t.value && <span className="ml-1 font-normal">· agora</span>}
                    </button>
                ))}
            </div>

            {aviso && <p className="text-sm text-on-surface rounded-lg bg-surface-variant/50 px-3 py-2 mb-3" role="status">{aviso}</p>}

            <div className="overflow-x-auto">
                <svg
                    role="img"
                    aria-label="Planta do evento para escolher o estande"
                    viewBox={`0 0 ${caixa.largura * CELULA + MARGEM * 2} ${caixa.altura * CELULA + MARGEM * 2}`}
                    className="w-full h-auto min-w-[36rem]"
                >
                    {layout.map((e) => {
                        const projeto = estandes[e.numero]?.[turno] ?? null;
                        const cores = projeto ? SITUACOES[projeto.situacao] ?? SITUACOES.fora_do_turno : VAZIO;
                        const x0 = MARGEM + e.x * CELULA;
                        const y0 = MARGEM + e.y * CELULA;

                        return (
                            <g
                                key={e.numero}
                                role="button"
                                tabIndex={0}
                                aria-label={descricao(e.numero, projeto)}
                                style={{ cursor: projeto ? 'pointer' : 'default' }}
                                onClick={() => clicar(e.numero, projeto)}
                                onKeyDown={(ev) => { if (ev.key === 'Enter') clicar(e.numero, projeto); }}
                            >
                                <title>{descricao(e.numero, projeto)}</title>
                                <rect
                                    x={x0}
                                    y={y0}
                                    width={CELULA - 1}
                                    height={CELULA - 1}
                                    rx="1.5"
                                    fill={cores.fill}
                                    stroke={cores.stroke}
                                    strokeWidth="0.6"
                                    strokeDasharray={!projeto || cores.tracejado ? '1.5 1' : undefined}
                                />
                                <text
                                    x={x0 + (CELULA - 1) / 2}
                                    y={y0 + (CELULA - 1) / 2 + 1.6}
                                    textAnchor="middle"
                                    fontSize="3.6"
                                    fontWeight="600"
                                    fill={cores.texto}
                                >
                                    {rotulo(e.numero)}
                                </text>
                            </g>
                        );
                    })}
                </svg>
            </div>

            {fora.length > 0 && (
                <div className="mt-3">
                    <p className="text-xs text-on-surface-variant mb-1">Estandes fora da planta</p>
                    <div className="flex flex-wrap gap-2">
                        {fora.map((p) => {
                            const cores = SITUACOES[p.situacao] ?? SITUACOES.fora_do_turno;
                            return (
                                <button
                                    key={`${p.numero}-${p.turno}`}
                                    type="button"
                                    onClick={() => clicar(p.numero, p)}
                                    aria-label={descricao(p.numero, p)}
                                    className="rounded-lg border px-2.5 py-1 text-xs font-semibold"
                                    style={{ background: cores.fill, borderColor: cores.stroke, color: cores.texto }}
                                >
                                    {rotulo(p.numero)} · {p.titulo}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            <div className="flex flex-wrap gap-x-4 gap-y-1 mt-3 text-xs text-on-surface-variant">
                {Object.entries(SITUACOES).filter(([, c]) => !c.semLegenda).map(([chave, c]) => (
                    <span key={chave} className="inline-flex items-center gap-1">
                        <span className="w-3 h-3 rounded-sm border" style={{ background: c.fill, borderColor: c.stroke, borderStyle: c.tracejado ? 'dashed' : 'solid' }} />
                        {c.legenda}
                    </span>
                ))}
            </div>
        </div>
    );
}
