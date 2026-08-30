import { useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { GradeCards } from '../components/CardsPainel.jsx';
import { getDashboard } from '../lib/admin.js';

/**
 * Aba "Dashboards": os números da edição, agrupados por assunto.
 *
 * Reúne **todos** os cards do painel — os mesmos que a aba Projetos mostrava
 * sozinha antes desta sprint. Aqui "Projetos (total)" é só número: quem quer a
 * lista entra pela aba Projetos, que é onde as telas de detalhe moram.
 *
 * Regra herdada do `AdminDashboardService`: fora os dois primeiros cards, que
 * existem para mostrar o rascunho, **todo card conta só projetos submetidos**.
 */
const SECOES = [
    {
        titulo: 'Projetos',
        descricao: 'Quantos projetos a edição tem, em que pé estão e em que categoria concorrem.',
        cards: [
            { key: 'projetos_total', label: 'Projetos (total)', icon: 'folder' },
            { type: 'status', label: 'Projetos por status', icon: 'donut_large' },
            { type: 'categoria', label: 'Projetos por categoria', icon: 'category' },
        ],
    },
    {
        titulo: 'Pessoas',
        descricao: 'Quem participa dos projetos submetidos, com o recorte por gênero.',
        cards: [
            { type: 'genero', generoKey: 'orientadores_genero', label: 'Orientadores', icon: 'person' },
            { type: 'genero', generoKey: 'alunos_genero', label: 'Alunos', icon: 'school' },
            { type: 'genero', generoKey: 'coorientadores_genero', label: 'Coorientadores', icon: 'group' },
        ],
    },
    {
        titulo: 'Camisetas',
        descricao: 'Quantas camisetas de cada tamanho encomendar, por público.',
        cards: [
            { type: 'camiseta', camisetaKey: 'orientadores_camisetas', label: 'Camisetas · Orientadores', icon: 'apparel' },
            { type: 'camiseta', camisetaKey: 'alunos_camisetas', label: 'Camisetas · Alunos', icon: 'apparel' },
            { type: 'camiseta', camisetaKey: 'coorientadores_camisetas', label: 'Camisetas · Coorientadores', icon: 'apparel' },
        ],
    },
    {
        titulo: 'Alunos por classe escolar',
        descricao: 'A quebra por série de cada classe. O técnico integrado é contado no Ensino Médio.',
        // A ordem e os rótulos vêm do backend (`App\Support\ClassesEscolares`).
        cards: [
            { type: 'classe', classeIndex: 0, icon: 'school' },
            { type: 'classe', classeIndex: 1, icon: 'school' },
            { type: 'classe', classeIndex: 2, icon: 'school' },
        ],
    },
    {
        titulo: 'Localidades',
        descricao: 'De onde vêm os projetos submetidos.',
        cards: [
            { key: 'escolas_com_projeto', label: 'Escolas com projeto', icon: 'apartment', verMais: '/admin/projetos-por-escola' },
            { key: 'cidades_com_projeto', label: 'Cidades com projeto', icon: 'location_city', verMais: '/admin/projetos-por-cidade' },
            { key: 'estados_com_projeto', label: 'Estados com projeto', icon: 'map', verMais: '/admin/projetos-por-estado' },
        ],
    },
];

export default function AdminDashboards() {
    const [m, setM] = useState(null);

    useEffect(() => { getDashboard().then(setM).catch(() => setM({})); }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Dashboards</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Os números da XVI FETECMS. Fora os dois primeiros cards, que existem para acompanhar o
                rascunho, todos contam <strong>apenas projetos submetidos</strong>.
            </p>

            {!m ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                SECOES.map((secao) => (
                    <section key={secao.titulo} className="mb-10">
                        <h2 className="font-display text-lg font-semibold text-on-surface">{secao.titulo}</h2>
                        <p className="text-sm text-on-surface-variant mb-3">{secao.descricao}</p>
                        <GradeCards cards={secao.cards} dados={m} />
                    </section>
                ))
            )}
        </AppShell>
    );
}
