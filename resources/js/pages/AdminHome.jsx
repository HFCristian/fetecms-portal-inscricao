import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { getDashboard } from '../lib/admin.js';

const CARDS = [
    { key: 'projetos_total', label: 'Projetos (total)', icon: 'folder', color: 'text-on-surface' },
    { key: 'projetos_submetidos', label: 'Submetidos', icon: 'task_alt', color: 'text-secondary' },
    { key: 'projetos_rascunho', label: 'Rascunhos', icon: 'edit_note', color: 'text-primary-container' },
    { key: 'orientadores', label: 'Orientadores', icon: 'person', color: 'text-on-surface' },
    { key: 'alunos', label: 'Alunos', icon: 'school', color: 'text-on-surface' },
    { key: 'coorientadores', label: 'Coorientadores', icon: 'group', color: 'text-on-surface' },
    { key: 'escolas_com_projeto', label: 'Escolas com projeto', icon: 'apartment', color: 'text-on-surface' },
    { key: 'cidades_com_projeto', label: 'Cidades com projeto', icon: 'location_city', color: 'text-on-surface' },
    { key: 'estados_com_projeto', label: 'Estados com projeto', icon: 'map', color: 'text-on-surface' },
];

export default function AdminHome() {
    const [metricas, setMetricas] = useState(null);

    useEffect(() => { getDashboard().then(setMetricas).catch(() => setMetricas({})); }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Painel do Administrador</h1>
            <p className="text-on-surface-variant mb-6">Visão geral da XVI FETECMS.</p>

            {!metricas ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="material-symbols-outlined animate-spin">progress_activity</span>
                </div>
            ) : (
                <div className="grid grid-cols-2 md:grid-cols-3 gap-4 mb-10">
                    {CARDS.map((c) => (
                        <div key={c.key} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 flex flex-col">
                            <span className="material-symbols-outlined text-primary-container">{c.icon}</span>
                            <div className={`text-3xl font-bold mt-1 ${c.color}`}>{metricas[c.key] ?? 0}</div>
                            <div className="text-xs text-on-surface-variant">{c.label}</div>
                            {c.key === 'projetos_total' && (
                                <Link
                                    to="/admin/projetos-por-area"
                                    className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-primary-container hover:text-primary self-start"
                                >
                                    Ver mais
                                    <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                                </Link>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </AppShell>
    );
}
