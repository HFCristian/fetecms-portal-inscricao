import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { LimitesAvaliadorCard, LimitesProjetoCard } from '../components/LimitesAvaliacaoCards.jsx';
import { getAvaliacaoConfig } from '../lib/admin.js';

/**
 * Parametrização → Avaliação Online.
 *
 * Ficou com os **limites** do edital. As datas do período (e as do período de
 * ajustes) mudaram-se para Parametrização → **Datas e períodos**, que reúne
 * todas as janelas da edição num lugar só.
 */
export default function ParametrizacaoAvaliacao() {
    const [config, setConfig] = useState(null);

    useEffect(() => {
        getAvaliacaoConfig()
            .then(setConfig)
            .catch(() => setConfig({
                min_por_avaliador: null, min_por_projeto: null,
                max_por_avaliador: null, max_por_projeto: null, categorias: [],
            }));
    }, []);

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação Online</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Os limites de avaliação do edital. As <strong>datas</strong> do período estão em{' '}
                <Link to="/admin/parametrizacao/datas" className="underline">Datas e períodos</Link>, e as
                regras do algoritmo continuam na aba <strong>Avaliação online</strong>.
            </p>

            {config === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    <LimitesAvaliadorCard config={config} onSalvo={setConfig} />
                    <LimitesProjetoCard config={config} onSalvo={setConfig} />
                </>
            )}
        </AppShell>
    );
}
