import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Toggle } from '../components/ui.jsx';
import AvaliacaoModal from '../components/AvaliacaoModal.jsx';
import { useAuth } from '../lib/auth.jsx';
import { getMinhaAvaliacao } from '../lib/avaliacao.js';

const PILL = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary text-on-secondary',
};

function botaoLabel(status) {
    if (status === 'em_andamento') return 'Continuar';
    if (status === 'concluida') return 'Ver';
    return 'Avaliar';
}

export default function AvaliadorHome() {
    const { user } = useAuth();
    const sub = user?.avaliador_profile?.subarea;
    const area = user?.avaliador_profile?.area;

    const [dados, setDados] = useState(null);
    // Demo já entra em "modo teste" (ignora a data). Para avaliador real, o backend
    // ignora o flag — ele continua travado pela data. O toggle só aparece para demo.
    const [modoTeste, setModoTeste] = useState(true);
    const [avaliando, setAvaliando] = useState(null); // avaliacao_id em avaliação

    const carregar = useCallback((teste) => {
        return getMinhaAvaliacao(teste)
            .then(setDados)
            .catch(() => setDados({ liberada: false, pode_avaliar: false, is_demo: false, projetos: [] }));
    }, []);

    useEffect(() => { carregar(modoTeste); }, [carregar, modoTeste]);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Painel do Avaliador</h1>
            <p className="text-on-surface-variant mb-4">
                {area ? <>Sua área: <strong>{area}</strong>{sub ? <> · subárea <strong>{sub}</strong></> : null}.</> : 'Bem-vindo(a).'}
            </p>

            {dados?.is_demo && (
                <div className="bg-primary-fixed/60 border border-primary-container/30 rounded-xl p-4 mb-4 max-w-3xl flex items-start gap-3">
                    <span className="material-symbols-outlined text-primary-container">science</span>
                    <div className="flex-1">
                        <Toggle
                            checked={modoTeste}
                            onChange={setModoTeste}
                            label="Modo de teste"
                            description="Avaliador demo: teste o fluxo completo mesmo antes da liberação. As avaliações são de teste e podem ser limpas pelo admin."
                        />
                    </div>
                </div>
            )}

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : !dados.pode_avaliar ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-10 text-center max-w-3xl">
                    <span className="material-symbols-outlined text-[48px] text-primary-container">event_upcoming</span>
                    <p className="text-on-surface mt-3 font-semibold">As avaliações ainda não foram liberadas</p>
                    <p className="text-on-surface-variant text-sm mt-1 max-w-md mx-auto">
                        {dados.liberada_em_label
                            ? <>Serão liberadas em <strong>{dados.liberada_em_label}</strong>. Os projetos designados para você aparecerão aqui.</>
                            : 'A partir da data definida pela organização, os projetos designados aparecerão aqui para leitura e nota (1 a 10).'}
                    </p>
                </div>
            ) : dados.projetos.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-10 text-center text-on-surface-variant max-w-3xl">
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
                                {p.status === 'concluida' && (
                                    <span className="text-xs text-on-surface-variant shrink-0">nota <strong className="text-secondary">{p.nota}</strong></span>
                                )}
                                <span className={`text-xs font-semibold px-2 py-0.5 rounded-full shrink-0 ${PILL[p.status] ?? 'bg-surface-variant'}`}>
                                    {p.status_label}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => setAvaliando(p.avaliacao_id)}
                                    className="shrink-0 text-sm font-semibold text-primary-container hover:text-primary border border-outline-variant rounded-lg px-3 py-1.5 hover:bg-surface-variant transition-colors"
                                >
                                    {botaoLabel(p.status)}
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {avaliando && (
                <AvaliacaoModal
                    avaliacaoId={avaliando}
                    teste={modoTeste && dados?.is_demo}
                    onFechar={() => setAvaliando(null)}
                    onAtualizado={() => carregar(modoTeste)}
                />
            )}
        </AppShell>
    );
}
