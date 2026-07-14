import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { getAvaliacaoProjetos } from '../lib/admin.js';

export default function AvaliacaoProjetos() {
    const [areas, setAreas] = useState(null);

    useEffect(() => {
        getAvaliacaoProjetos().then(setAreas).catch(() => setAreas([]));
    }, []);

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Projetos submetidos</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Projetos submetidos por área e quantas avaliações cada um já recebeu.
            </p>

            {areas === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : areas.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhum projeto submetido ainda.
                </div>
            ) : (
                <div className="space-y-6 max-w-3xl">
                    {areas.map((grupo) => (
                        <div key={grupo.area_id} className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                            <div className="px-4 py-3 bg-surface-variant/40 flex items-center justify-between gap-2">
                                <h2 className="font-display font-semibold text-on-surface truncate">{grupo.area}</h2>
                                <span className="text-xs text-on-surface-variant shrink-0">
                                    {grupo.projetos.length} {grupo.projetos.length === 1 ? 'projeto' : 'projetos'}
                                </span>
                            </div>
                            <ul className="divide-y divide-outline-variant/30">
                                {grupo.projetos.map((p) => (
                                    <li key={p.id} className="px-4 py-3 flex items-center gap-3">
                                        <span className="material-symbols-outlined text-primary-container">description</span>
                                        <span className="flex-1 min-w-0 text-sm text-on-surface truncate">{p.titulo}</span>
                                        <span className="text-xs font-semibold text-on-surface-variant bg-surface-variant rounded-full px-2.5 py-0.5 shrink-0">
                                            {p.avaliacoes_recebidas} {p.avaliacoes_recebidas === 1 ? 'avaliação' : 'avaliações'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </AppShell>
    );
}
