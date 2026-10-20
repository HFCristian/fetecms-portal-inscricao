import { escalaAvaliacoes, corDaArea, resumoDoPainel } from '../lib/mapaTelaCheia.js';

/** As cores das faixas 0, 1, 2… — a mesma escala que pinta o mapa. */
function coresDasFaixas(maximo) {
    return ['#ffffff', ...escalaAvaliacoes(maximo)];
}

/**
 * Uma barra empilhada de projetos por avaliações recebidas. Os segmentos têm
 * 2px de folga entre si, e cada um diz o número no `title` (o mapa costuma
 * estar projetado, então os números também vão escritos ao lado).
 */
function BarraFaixas({ faixas, rotulos, cores, total, rotulo }) {
    return (
        <div className="flex h-3 w-full gap-[2px] rounded-sm overflow-hidden bg-surface-variant/40" role="img" aria-label={rotulo}>
            {faixas.map((n, i) => n > 0 && (
                <div
                    key={rotulos[i]}
                    title={`${rotulos[i]} avaliação(ões): ${n} projeto(s)`}
                    style={{ width: `${(n / Math.max(1, total)) * 100}%`, background: cores[i] }}
                    className={i === 0 ? 'border border-[#b9b2c4]' : ''}
                />
            ))}
        </div>
    );
}

/**
 * O painel da tela cheia do Mapa do Evento (Sprint 200): as avaliações
 * presenciais do turno, no espírito do resumo da avaliação online.
 *
 * - a **barra** de avaliações feitas sobre a meta (projetos × avaliações por
 *   projeto), que vai enchendo ao longo do turno;
 * - **projetos por quantidade de avaliações recebidas**, no geral e por área;
 * - quantos projetos de cada área já receberam avaliação da **comissão
 *   especial**.
 *
 * Calculado das mesmas linhas que pintam o mapa: atualiza junto com ele, a
 * cada 5 segundos, e nunca discorda do desenho.
 *
 * O botão **Ver avaliações por projeto** (Sprint 248) leva à tela que separa o
 * que falta em cada projeto — comum e do comitê —, no mapa e em lista.
 */
export default function PainelAvaliacoesMapa({ linhas, maximo, onFechar, onDetalhar }) {
    const r = resumoDoPainel(linhas, maximo);
    const cores = coresDasFaixas(r.maximo);

    return (
        <aside
            className="absolute right-0 top-0 bottom-0 w-[22rem] max-w-full overflow-y-auto fetec-scroll bg-surface-container-lowest/95 backdrop-blur rounded-xl fetec-card-shadow p-4 space-y-5 z-10"
            aria-label="Dashboard das avaliações"
        >
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h3 className="font-display text-base font-semibold text-primary">Avaliações no estande</h3>
                    <p className="text-xs text-on-surface-variant">{r.total} projeto(s) neste turno · meta de {r.maximo} por projeto</p>
                </div>
                <button type="button" onClick={onFechar} className="text-on-surface-variant hover:text-primary" aria-label="Fechar o dashboard">
                    <span className="material-symbols-outlined">close</span>
                </button>
            </div>

            {/* Sprint 248: o que falta em cada projeto, comum e do comitê. */}
            {onDetalhar && (
                <button
                    type="button"
                    onClick={onDetalhar}
                    className="w-full inline-flex items-center justify-center gap-1.5 rounded-lg border-2 border-primary-container px-3 py-2 text-sm font-semibold text-primary-container hover:bg-primary-fixed"
                >
                    <span className="material-symbols-outlined text-[18px]">table_view</span>
                    Ver avaliações por projeto
                </button>
            )}

            <section aria-label="Avaliações realizadas">
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

            <section aria-label="Projetos por avaliações recebidas">
                <p className="text-sm font-semibold text-on-surface mb-2">Projetos por avaliações recebidas</p>
                <BarraFaixas faixas={r.geral} rotulos={r.rotulos} cores={cores} total={r.total} rotulo="Geral" />
                <ul className="mt-2 grid gap-2 text-center" style={{ gridTemplateColumns: `repeat(${r.geral.length}, minmax(0, 1fr))` }}>
                    {r.geral.map((n, i) => (
                        <li key={r.rotulos[i]} className="rounded-lg bg-surface px-1 py-1.5">
                            <span className="flex items-center justify-center gap-1 text-[11px] text-on-surface-variant">
                                <span className="w-2.5 h-2.5 rounded-sm border border-[#b9b2c4]" style={{ background: cores[i] }} />
                                {r.rotulos[i]}
                            </span>
                            <span className="block font-display text-lg font-semibold text-on-surface">{n}</span>
                        </li>
                    ))}
                </ul>
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
                                    <span className="text-on-surface-variant shrink-0 tabular-nums">
                                        {a.faixas.map((n, i) => `${r.rotulos[i]}: ${n}`).join(' · ')}
                                    </span>
                                </div>
                                <div className="mt-1">
                                    <BarraFaixas faixas={a.faixas} rotulos={r.rotulos} cores={cores} total={a.total} rotulo={a.area} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section aria-label="Comissão especial">
                <div className="flex items-baseline justify-between mb-2">
                    <p className="text-sm font-semibold text-on-surface">Avaliados pela comissão especial</p>
                    <p className="text-xs text-on-surface-variant">{r.comComite} de {r.total}</p>
                </div>
                <ul className="space-y-1.5">
                    {r.areas.map((a) => (
                        <li key={a.area} className="grid grid-cols-[1fr_auto] items-center gap-x-2 text-xs">
                            <span className="truncate text-on-surface">{a.area}</span>
                            <span className="text-on-surface-variant tabular-nums">{a.comComite} de {a.total}</span>
                            <div className="col-span-2 h-1.5 rounded-full bg-surface-variant overflow-hidden">
                                <div className="h-full rounded-full bg-[#6250d6]" style={{ width: `${a.total ? (a.comComite / a.total) * 100 : 0}%` }} />
                            </div>
                        </li>
                    ))}
                </ul>
            </section>
        </aside>
    );
}
