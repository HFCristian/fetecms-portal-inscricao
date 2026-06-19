import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { getDashboard } from '../lib/admin.js';

// Cards simples (1 número). O card de status (submetidos + rascunho) é tratado à parte.
const CARDS = [
    { key: 'projetos_total', label: 'Projetos (total)', icon: 'folder', verMais: '/admin/projetos-por-area' },
    { type: 'status', label: 'Projetos por status', icon: 'donut_large' },
    { key: 'orientadores', label: 'Orientadores', icon: 'person' },
    { key: 'alunos', label: 'Alunos', icon: 'school' },
    { key: 'coorientadores', label: 'Coorientadores', icon: 'group' },
    { key: 'escolas_com_projeto', label: 'Escolas com projeto', icon: 'apartment', verMais: '/admin/projetos-por-escola' },
    { key: 'cidades_com_projeto', label: 'Cidades com projeto', icon: 'location_city', verMais: '/admin/projetos-por-cidade' },
    { key: 'estados_com_projeto', label: 'Estados com projeto', icon: 'map', verMais: '/admin/projetos-por-estado' },
];

function VerMais({ to }) {
    return (
        <Link
            to={to}
            className="flex flex-row items-center justify-center text-sm font-semibold text-primary-container hover:text-primary hover:border-b-2 border-primary transition ease-in-out w-2/4"
        >
            <p>Ver mais</p>
            <span class="material-symbols-outlined text-[18px]">chevron_right</span>
        </Link>
    );
}

export default function AdminHome() {
    const [m, setM] = useState(null);

    useEffect(() => { getDashboard().then(setM).catch(() => setM({})); }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Painel do Administrador</h1>
            <p className="text-on-surface-variant mb-6">Visão geral da XVI FETECMS.</p>

            {!m ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="material-symbols-outlined animate-spin">progress_activity</span>
                </div>
            ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 mb-10">
                    {CARDS.map((c) => (
                        <div key={c.key ?? c.type} className="flex flex-col items-center text-center gap-2 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <span className="material-symbols-outlined text-primary-container text-2xl">{c.icon}</span>

                            {c.type === 'status' ? (
                                <>
                                    <div className="flex gap-4 py-2">
                                        <div>
                                            <div className="text-3xl font-bold text-secondary">{m.projetos_submetidos ?? 0}</div>
                                            <div className="text-xs text-on-surface-variant">Submetidos</div>
                                        </div>
                                        <div>
                                            <div className="text-3xl font-bold text-primary-container">{m.projetos_rascunho ?? 0}</div>
                                            <div className="text-xs text-on-surface-variant">Rascunho</div>
                                        </div>
                                    </div>
                                    <div className="text-sm text-on-surface-variant">{c.label}</div>
                                </>
                            ) : (
                                <>
                                    <div className='py-2'>
                                        <div className="text-3xl font-bold text-on-surface">{m[c.key] ?? 0}</div>
                                        <div className="text-sm text-on-surface-variant">{c.label}</div>
                                    </div>
                                </>
                            )}

                            {c.verMais && <VerMais to={c.verMais} />}
                        </div>
                    ))}
                </div>
            )}
        </AppShell>
    );
}
