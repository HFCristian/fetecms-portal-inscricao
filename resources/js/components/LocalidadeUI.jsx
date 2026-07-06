import { useState } from 'react';

// Rótulo de uma localidade: "Nome (UF)" quando há UF; só o nome caso contrário.
const rotulo = (item) => (item.uf ? `${item.nome} (${item.uf})` : item.nome);

export function Carregando() {
    return (
        <div className="text-center py-10 text-on-surface-variant">
            <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
        </div>
    );
}

export function Vazio({ children = 'Nenhum projeto cadastrado ainda.' }) {
    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">
            {children}
        </div>
    );
}

// Pílulas com a contagem por status: total, submetidos e rascunho.
export function Contagens({ total, submetidos, rascunho }) {
    return (
        <div className="flex items-center gap-1.5 shrink-0 flex-wrap justify-end">
            <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-surface-variant text-on-surface-variant">{total} total</span>
            <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">{submetidos} subm.</span>
            <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-primary-fixed text-primary-container">{rascunho} rasc.</span>
        </div>
    );
}

// Gráfico de barras (top N) — barra empilhada submetidos (verde) + rascunho (roxo).
export function ProjetosBarChart({ items, max = 10, titulo = 'Mais projetos' }) {
    const top = (items ?? []).slice(0, max);
    if (top.length === 0) return null;
    const maior = top.reduce((m, i) => Math.max(m, i.total), 0) || 1;

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-6">
            <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
                <h2 className="font-display text-primary font-semibold">{titulo}</h2>
                <div className="flex items-center gap-4 text-xs text-on-surface-variant">
                    <span className="inline-flex items-center gap-1"><span className="w-3 h-3 rounded-sm bg-secondary" /> Submetidos</span>
                    <span className="inline-flex items-center gap-1"><span className="w-3 h-3 rounded-sm bg-primary-container" /> Rascunho</span>
                </div>
            </div>
            <ul className="space-y-3">
                {top.map((i) => (
                    <li key={i.id ?? i.nome}>
                        <div className="flex items-center justify-between gap-2 text-sm mb-1">
                            <span className="font-medium text-on-surface truncate">{rotulo(i)}</span>
                            <span className="text-on-surface-variant shrink-0">{i.total}</span>
                        </div>
                        <div className="h-3 rounded-full bg-surface-variant overflow-hidden">
                            <div className="h-full flex" style={{ width: `${(i.total / maior) * 100}%` }}>
                                <div className="bg-secondary h-full" style={{ width: `${i.total ? (i.submetidos / i.total) * 100 : 0}%` }} />
                                <div className="bg-primary-container h-full" style={{ width: `${i.total ? (i.rascunho / i.total) * 100 : 0}%` }} />
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

// Item de localidade que abre/fecha (dropdown) revelando as escolas daquele lugar.
export function LocalidadeAccordion({ item }) {
    const [open, setOpen] = useState(false);
    const escolas = item.escolas ?? [];

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                className="w-full flex items-center justify-between gap-3 p-4 text-left hover:bg-surface-variant/40 transition-colors"
            >
                <span className="flex items-center gap-2 min-w-0">
                    <span className={`material-symbols-outlined text-on-surface-variant transition-transform ${open ? 'rotate-90' : ''}`}>chevron_right</span>
                    <span className="font-semibold text-on-surface truncate">{rotulo(item)}</span>
                </span>
                <Contagens total={item.total} submetidos={item.submetidos} rascunho={item.rascunho} />
            </button>
            {open && (
                <ul className="px-4 pb-3 border-t border-outline-variant/30 divide-y divide-outline-variant/30">
                    {escolas.length === 0 && (
                        <li className="py-2 text-sm text-on-surface-variant">Nenhuma escola informada.</li>
                    )}
                    {escolas.map((e) => (
                        <li key={e.id ?? e.nome} className="py-2 flex items-center justify-between gap-3">
                            <span className="flex items-center gap-2 min-w-0 text-sm text-on-surface">
                                <span className="material-symbols-outlined text-[18px] text-on-surface-variant shrink-0">apartment</span>
                                <span className="truncate">{e.nome}</span>
                            </span>
                            <Contagens total={e.total} submetidos={e.submetidos} rascunho={e.rascunho} />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

// Vista padrão de estado/cidade: gráfico + lista de dropdowns com as escolas.
export function ListaComEscolas({ items }) {
    if (items === null) return <Carregando />;
    if (items.length === 0) return <Vazio />;

    return (
        <>
            <ProjetosBarChart items={items} />
            <div className="space-y-3">
                {items.map((i) => <LocalidadeAccordion key={i.id ?? i.nome} item={i} />)}
            </div>
        </>
    );
}
