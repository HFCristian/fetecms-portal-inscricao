import { useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { useAuth } from '../lib/auth.jsx';
import { getMinhaAvaliacao } from '../lib/avaliacao.js';

const STATUS_LABEL = {
    designada: 'Designada',
    em_andamento: 'Em andamento',
    concluida: 'Concluída',
};

export default function AvaliadorHome() {
    const { user } = useAuth();
    const sub = user?.avaliador_profile?.subarea;
    const area = user?.avaliador_profile?.area;
    const [dados, setDados] = useState(null);

    useEffect(() => {
        getMinhaAvaliacao().then(setDados).catch(() => setDados({ liberada: false, liberada_em: null, projetos: [] }));
    }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Painel do Avaliador</h1>
            <p className="text-on-surface-variant mb-6">
                {area ? <>Sua área: <strong>{area}</strong>{sub ? <> · subárea <strong>{sub}</strong></> : null}.</> : 'Bem-vindo(a).'}
            </p>

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : !dados.liberada ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-10 text-center">
                    <span className="material-symbols-outlined text-[48px] text-primary-container">event_upcoming</span>
                    <p className="text-on-surface mt-3 font-semibold">As avaliações ainda não foram liberadas</p>
                    <p className="text-on-surface-variant text-sm mt-1 max-w-md mx-auto">
                        {dados.liberada_em
                            ? <>Elas serão liberadas em <strong>{new Date(dados.liberada_em).toLocaleString('pt-BR')}</strong>. Os projetos designados para você aparecerão aqui.</>
                            : 'A partir da data definida pela organização, os projetos designados para você aparecerão aqui para leitura e atribuição de nota (1 a 10).'}
                    </p>
                </div>
            ) : dados.projetos.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-10 text-center text-on-surface-variant">
                    <span className="material-symbols-outlined text-[48px] text-primary-container">inbox</span>
                    <p className="mt-3 text-sm">Nenhum projeto designado a você por enquanto.</p>
                </div>
            ) : (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden max-w-3xl">
                    <div className="px-4 py-3 bg-surface-variant/40">
                        <h2 className="font-display font-semibold text-on-surface">Projetos designados a você</h2>
                    </div>
                    <ul className="divide-y divide-outline-variant/30">
                        {dados.projetos.map((p) => (
                            <li key={p.avaliacao_id} className="px-4 py-3 flex items-center gap-3">
                                <span className="material-symbols-outlined text-primary-container">description</span>
                                <div className="flex-1 min-w-0">
                                    <p className="text-sm text-on-surface truncate">{p.titulo}</p>
                                    {p.area && <p className="text-xs text-on-surface-variant truncate">{p.area}</p>}
                                </div>
                                <span className="text-xs font-semibold text-on-surface-variant bg-surface-variant rounded-full px-2.5 py-0.5 shrink-0">
                                    {p.status_label || STATUS_LABEL[p.status] || p.status}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </AppShell>
    );
}
