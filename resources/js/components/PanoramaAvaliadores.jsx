import { useEffect, useState } from 'react';
import { getAvaliadores } from '../lib/admin.js';

// Contagens gerais do corpo de avaliadores, no topo de "Avaliadores Online".
const CARDS = [
    { key: 'total', label: 'Avaliadores (total)', icon: 'fact_check' },
    { key: 'ativos', label: 'Ativos', icon: 'how_to_reg' },
    { key: 'inativos', label: 'Inativos', icon: 'person_off' },
];

/**
 * Panorama dos avaliadores: totais (total/ativos/inativos) e a distribuição por
 * área do conhecimento em barras. Vive no topo da tela "Avaliadores Online" —
 * antes era a aba "Avaliadores", removida por duplicar o mesmo assunto.
 */
export default function PanoramaAvaliadores() {
    const [m, setM] = useState(null);

    useEffect(() => {
        getAvaliadores().then(setM).catch(() => setM({ total: 0, ativos: 0, inativos: 0, por_area: [] }));
    }, []);

    if (!m) {
        return (
            <div className="text-center py-10 text-on-surface-variant">
                <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
            </div>
        );
    }

    const maxArea = m.por_area?.reduce((mx, a) => Math.max(mx, a.total), 0) || 1;

    return (
        <>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4 max-w-3xl">
                {CARDS.map((c) => (
                    <div key={c.key} className="flex flex-col items-center text-center gap-2 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                        <span className="material-symbols-outlined text-primary-container text-2xl">{c.icon}</span>
                        <div className="text-3xl font-bold text-on-surface">{m[c.key] ?? 0}</div>
                        <div className="text-sm text-on-surface-variant">{c.label}</div>
                    </div>
                ))}
            </div>

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
                <h2 className="font-display text-primary font-semibold mb-4">Distribuição por área</h2>
                {(!m.por_area || m.por_area.length === 0) ? (
                    <p className="text-sm text-on-surface-variant">Nenhum avaliador cadastrado ainda.</p>
                ) : (
                    <ul className="space-y-3">
                        {m.por_area.map((a) => (
                            <li key={a.area_id}>
                                <div className="flex items-center justify-between text-sm mb-1 gap-2">
                                    <span className="text-on-surface truncate">{a.area}</span>
                                    <span className="font-semibold text-on-surface-variant shrink-0">{a.total}</span>
                                </div>
                                <div className="h-2 rounded-full bg-surface-variant overflow-hidden">
                                    <div
                                        className="h-full bg-primary-container rounded-full"
                                        style={{ width: `${Math.round((a.total / maxArea) * 100)}%` }}
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
