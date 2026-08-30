import { useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { GradeCards } from '../components/CardsPainel.jsx';
import { getDashboard } from '../lib/admin.js';

/**
 * Aba "Projetos" (`/admin/projetos`).
 *
 * Fica com o recorte de projetos e de onde eles vêm; o restante dos números
 * (pessoas, camisetas, classes escolares) mudou-se para a aba **Dashboards**.
 * O que sobra aqui é justamente o que tem tela de detalhe atrás — daí cada card
 * de localidade, e o total, levarem a uma lista.
 */
const CARDS = [
    { key: 'projetos_total', label: 'Projetos (total)', icon: 'folder', verMais: '/admin/projetos-por-area' },
    { type: 'status', label: 'Projetos por status', icon: 'donut_large' },
    { type: 'categoria', label: 'Projetos por categoria', icon: 'category' },
    { key: 'escolas_com_projeto', label: 'Escolas com projeto', icon: 'apartment', verMais: '/admin/projetos-por-escola' },
    { key: 'cidades_com_projeto', label: 'Cidades com projeto', icon: 'location_city', verMais: '/admin/projetos-por-cidade' },
    { key: 'estados_com_projeto', label: 'Estados com projeto', icon: 'map', verMais: '/admin/projetos-por-estado' },
];

export default function AdminHome() {
    const [m, setM] = useState(null);

    useEffect(() => { getDashboard().then(setM).catch(() => setM({})); }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Projetos</h1>
            <p className="text-on-surface-variant mb-6">
                Os projetos da XVI FETECMS e de onde eles vêm. Os demais números estão em{' '}
                <strong>Dashboards</strong>.
            </p>

            {!m ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <div className="mb-10">
                    <GradeCards cards={CARDS} dados={m} />
                </div>
            )}
        </AppShell>
    );
}
