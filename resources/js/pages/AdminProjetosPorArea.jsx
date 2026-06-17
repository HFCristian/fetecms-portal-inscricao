import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { getProjetosPorArea } from '../lib/admin.js';

const STATUS_PILL = {
    rascunho: 'bg-primary-fixed text-primary-container',
    submetido: 'bg-secondary-container text-on-secondary-container',
    aprovado: 'bg-secondary-container text-on-secondary-container',
    rejeitado: 'bg-error-container text-on-error-container',
};

export default function AdminProjetosPorArea() {
    const [grupos, setGrupos] = useState(null);

    useEffect(() => { getProjetosPorArea().then(setGrupos).catch(() => setGrupos([])); }, []);

    return (
        <AppShell>
            <Link to="/admin" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Painel do Administrador
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Projetos por área do conhecimento</h1>
            <p className="text-sm text-on-surface-variant mb-6">
                Inclui rascunhos. Projetos sem área aparecem em “Área ainda não informada”.
            </p>

            {grupos === null ? (
                <div className="text-center py-6 text-on-surface-variant">
                    <span className="material-symbols-outlined animate-spin">progress_activity</span>
                </div>
            ) : grupos.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">
                    Nenhum projeto cadastrado ainda.
                </div>
            ) : (
                <div className="space-y-4">
                    {grupos.map((g) => (
                        <div key={g.area_id ?? 'sem-area'} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <div className="flex items-center justify-between mb-3">
                                <h3 className="font-semibold text-on-surface">{g.area}</h3>
                                <span className="text-xs text-on-surface-variant">{g.total} projeto(s)</span>
                            </div>
                            <ul className="divide-y divide-outline-variant/30">
                                {g.projetos.map((p) => (
                                    <li key={p.id} className="py-2 flex items-center gap-3">
                                        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full shrink-0 ${STATUS_PILL[p.status] ?? ''}`}>
                                            {p.status_label}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium text-on-surface truncate">
                                                {p.titulo || <span className="italic text-on-surface-variant">Sem título</span>}
                                            </p>
                                            <p className="text-xs text-on-surface-variant truncate">
                                                {p.categoria_label || '—'}
                                            </p>
                                        </div>
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
