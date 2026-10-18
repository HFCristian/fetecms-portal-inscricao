import { corDaArea } from '../lib/mapaTelaCheia.js';
import { CORES_COMITE, resumoComite } from '../lib/comiteAcompanhamento.js';

/** Um número grande com o rótulo embaixo. */
function Numero({ valor, label, cor }) {
    return (
        <li className="rounded-lg bg-surface px-2 py-2 text-center">
            <span className="block font-display text-xl font-semibold" style={{ color: cor ?? undefined }}>{valor}</span>
            <span className="block text-[11px] leading-tight text-on-surface-variant">{label}</span>
        </li>
    );
}

/**
 * Uma barra empilhada dos projetos de um recorte pela cor do estande: em
 * avaliação, avaliados, designados e sem avaliação.
 */
function BarraSituacoes({ r, rotulo }) {
    const n = r.porSituacao;
    const partes = [
        { n: n.em_avaliacao, cor: CORES_COMITE.em_avaliacao.fill, label: 'em avaliação' },
        { n: n.avaliado, cor: CORES_COMITE.avaliado.fill, label: 'avaliado(s)' },
        { n: n.designado, cor: CORES_COMITE.designado.fill, label: 'designado(s)' },
        { n: n.pendente, cor: CORES_COMITE.pendente.fill, label: 'sem avaliação', borda: true },
    ];

    return (
        <div className="flex h-3 w-full gap-[2px] rounded-sm overflow-hidden bg-surface-variant/40" role="img" aria-label={rotulo}>
            {partes.map((p) => p.n > 0 && (
                <div
                    key={p.label}
                    title={`${p.n} ${p.label}`}
                    style={{ width: `${(p.n / Math.max(1, r.total)) * 100}%`, background: p.cor }}
                    className={p.borda ? 'border border-[#b9b2c4]' : ''}
                />
            ))}
        </div>
    );
}

/**
 * O dashboard do mapa do comitê: a barra de avaliações feitas sobre a meta
 * (projetos do turno × vagas da comissão), os projetos pelo que o comitê fez
 * neles — no geral e por área —, os avaliadores da comissão agora e quem mais
 * avaliou. Calculado das mesmas linhas que pintam o mapa: atualiza junto e
 * nunca discorda do desenho.
 */
export default function PainelComiteMapa({ linhas, avaliadores, vagas, turnoLabel, onFechar, flutuante = false }) {
    const r = resumoComite(linhas, avaliadores, vagas);

    return (
        <aside
            className={`${flutuante ? 'absolute right-0 top-0 bottom-0 w-[22rem] max-w-full z-10 bg-surface-container-lowest/95 backdrop-blur' : 'bg-surface-container-lowest'} overflow-y-auto fetec-scroll rounded-xl fetec-card-shadow p-4 space-y-5`}
            aria-label="Dashboard do comitê"
        >
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h3 className="font-display text-base font-semibold text-primary">Avaliações do comitê</h3>
                    <p className="text-xs text-on-surface-variant">
                        {r.total} projeto(s) no {turnoLabel ?? 'turno'} · meta de {r.vagas} por projeto
                    </p>
                </div>
                {onFechar && (
                    <button type="button" onClick={onFechar} className="text-on-surface-variant hover:text-primary" aria-label="Fechar o dashboard">
                        <span className="material-symbols-outlined">close</span>
                    </button>
                )}
            </div>

            <section aria-label="Avaliações do comitê realizadas">
                <div className="flex items-baseline justify-between">
                    <p className="text-sm font-semibold text-on-surface">Avaliações realizadas</p>
                    <p className="text-sm text-on-surface"><strong>{r.progresso.pct}%</strong></p>
                </div>
                <div
                    className="mt-1 h-4 rounded-full bg-surface-variant overflow-hidden"
                    role="progressbar"
                    aria-valuenow={r.progresso.feitas}
                    aria-valuemin={0}
                    aria-valuemax={r.progresso.meta}
                >
                    <div className="h-full rounded-full bg-primary-container transition-[width] duration-700" style={{ width: `${r.progresso.pct}%` }} />
                </div>
                <p className="text-xs text-on-surface-variant mt-1">{r.progresso.feitas} de {r.progresso.meta} avaliações previstas</p>
            </section>

            <section aria-label="Projetos do turno">
                <p className="text-sm font-semibold text-on-surface mb-2">Projetos do turno</p>
                <BarraSituacoes r={r} rotulo="Projetos do turno pelo que o comitê fez" />
                <ul className="mt-2 grid grid-cols-4 gap-2">
                    <Numero valor={r.emAvaliacao} label="em avaliação" cor={CORES_COMITE.em_avaliacao.fill} />
                    <Numero valor={r.avaliados} label="avaliados" cor={CORES_COMITE.avaliado.stroke} />
                    <Numero valor={r.designados} label="designados" cor={CORES_COMITE.designado.stroke} />
                    <Numero valor={r.pendentes} label="sem avaliação" />
                </ul>
                {r.prontosSemComite > 0 && (
                    <p className="text-xs text-on-surface mt-2 rounded-lg bg-[#fff4cc] px-2 py-1.5">
                        <strong>{r.prontosSemComite}</strong> projeto(s) pronto(s) para avaliação ainda sem ninguém do comitê.
                    </p>
                )}
            </section>

            <section aria-label="Avaliadores da comissão">
                <p className="text-sm font-semibold text-on-surface mb-2">Avaliadores da comissão</p>
                <ul className="grid grid-cols-3 gap-2">
                    <Numero valor={r.avaliadores.avaliando} label="avaliando agora" cor={CORES_COMITE.em_avaliacao.fill} />
                    <Numero valor={r.avaliadores.designados} label="com designação" cor={CORES_COMITE.designado.stroke} />
                    <Numero valor={r.avaliadores.livres} label="livres" />
                </ul>
                <p className="text-xs text-on-surface-variant mt-1">
                    {r.avaliadores.total} na comissão · {r.avaliadores.concluidas} avaliação(ões) enviada(s) no evento
                </p>
                {r.destaques.length > 0 && (
                    <ol className="mt-2 space-y-1 text-xs">
                        {r.destaques.map((d, i) => (
                            <li key={d.id} className="flex items-center justify-between gap-2">
                                <span className="truncate text-on-surface">{i + 1}. {d.nome}</span>
                                <span className="text-on-surface-variant tabular-nums shrink-0">{d.total}</span>
                            </li>
                        ))}
                    </ol>
                )}
            </section>

            <section aria-label="Por área do conhecimento">
                <p className="text-sm font-semibold text-on-surface mb-2">Por área do conhecimento</p>
                {r.areas.length === 0 ? (
                    <p className="text-xs text-on-surface-variant">Nenhum projeto alocado neste turno.</p>
                ) : (
                    <ul className="space-y-2.5">
                        {r.areas.map((a) => (
                            <li key={a.area}>
                                <div className="flex items-center justify-between gap-2 text-xs">
                                    <span className="flex items-center gap-1.5 min-w-0 text-on-surface">
                                        <span className="w-2.5 h-2.5 rounded-sm shrink-0" style={{ background: corDaArea(a.sigla) }} />
                                        <span className="truncate">{a.area}</span>
                                    </span>
                                    <span className="text-on-surface-variant shrink-0 tabular-nums">{a.avaliados} de {a.total}</span>
                                </div>
                                <div className="mt-1">
                                    <BarraSituacoes r={a} rotulo={a.area} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </aside>
    );
}
