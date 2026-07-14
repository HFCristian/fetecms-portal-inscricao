import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { getAvaliacaoAvaliadores } from '../lib/admin.js';

function Metrica({ valor, rotulo, cor }) {
    return (
        <div className="text-center w-16">
            <div className={`text-lg font-bold ${cor}`}>{valor}</div>
            <div className="text-[10px] text-on-surface-variant leading-tight">{rotulo}</div>
        </div>
    );
}

export default function AvaliacaoAvaliadores() {
    const [areas, setAreas] = useState(null);

    useEffect(() => {
        getAvaliacaoAvaliadores().then(setAreas).catch(() => setAreas([]));
    }, []);

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliadores por área</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Progresso de cada avaliador — no máximo 3 projetos por avaliador.
            </p>

            {areas === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : areas.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhum avaliador cadastrado ainda.
                </div>
            ) : (
                <div className="space-y-6 max-w-3xl">
                    {areas.map((grupo) => (
                        <div key={grupo.area_id} className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                            <div className="px-4 py-3 bg-surface-variant/40 flex items-center justify-between gap-2">
                                <h2 className="font-display font-semibold text-on-surface truncate">{grupo.area}</h2>
                                <span className="text-xs text-on-surface-variant shrink-0">
                                    {grupo.avaliadores.length} {grupo.avaliadores.length === 1 ? 'avaliador' : 'avaliadores'}
                                </span>
                            </div>
                            <ul className="divide-y divide-outline-variant/30">
                                {grupo.avaliadores.map((a) => (
                                    <li key={a.id} className="px-4 py-3 flex items-center gap-3">
                                        <span className="material-symbols-outlined text-primary-container">account_circle</span>
                                        <span className="flex-1 min-w-0 text-sm text-on-surface truncate">{a.nome}</span>
                                        <div className="flex items-center gap-2 shrink-0">
                                            <Metrica valor={a.em_avaliacao} rotulo="Em avaliação" cor="text-primary-container" />
                                            <Metrica valor={a.avaliou} rotulo="Já avaliou" cor="text-secondary" />
                                            <Metrica valor={a.faltam} rotulo="Faltam" cor="text-on-surface" />
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
