import { render, screen, within } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ to, children }) => <a href={to}>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getDashboard: vi.fn(() => Promise.resolve({
        projetos_total: 10, projetos_submetidos: 6, projetos_rascunho: 4,
        orientadores: 5, alunos: 20, coorientadores: 3,
        orientadores_genero: { f: 3, m: 2, outros: 0 },
        alunos_genero: { f: 11, m: 8, outros: 1 },
        coorientadores_genero: { f: 1, m: 1, outros: 1 },
        projetos_categoria: [
            { value: 'fetec_jr', label: 'FETEC Jr', total: 7 },
            { value: 'fetecms', label: 'FETECMS', total: 2 },
            { value: 'fetecms_fundect', label: 'FETECMS FUNDECT', total: 1 },
        ],
        orientadores_camisetas: { total: 5, tamanhos: [
            { tamanho: 'PP', total: 0 }, { tamanho: 'P', total: 1 }, { tamanho: 'M', total: 2 },
            { tamanho: 'G', total: 1 }, { tamanho: 'GG', total: 0 }, { tamanho: 'XG', total: 1 },
        ] },
        alunos_camisetas: { total: 20, tamanhos: [
            { tamanho: 'PP', total: 4 }, { tamanho: 'P', total: 6 }, { tamanho: 'M', total: 5 },
            { tamanho: 'G', total: 3 }, { tamanho: 'GG', total: 1 }, { tamanho: 'XG', total: 0 },
        ] },
        coorientadores_camisetas: { total: 3, tamanhos: [
            { tamanho: 'PP', total: 0 }, { tamanho: 'P', total: 0 }, { tamanho: 'M', total: 2 },
            { tamanho: 'G', total: 1 }, { tamanho: 'GG', total: 0 }, { tamanho: 'XG', total: 0 },
        ] },
        alunos_classes: [
            { chave: 'fundamental_i', label: 'Ensino Fundamental I', total: 3, series: [
                { serie: '3º ano', total: 2 }, { serie: '4º ano', total: 0 },
                { serie: '5º ano', total: 1 },
            ] },
            { chave: 'fundamental_ii', label: 'Ensino Fundamental II', total: 5, series: [
                { serie: '6º ano', total: 1 }, { serie: '7º ano', total: 1 },
                { serie: '8º ano', total: 1 }, { serie: '9º ano', total: 1 },
            ] },
            { chave: 'medio', label: 'Ensino Médio', total: 12, series: [
                { serie: '1º ano', total: 5 }, { serie: '2º ano', total: 4 },
                { serie: '3º ano', total: 2 }, { serie: '4º ano', total: 1 },
            ] },
        ],
        escolas_com_projeto: 2, cidades_com_projeto: 2, estados_com_projeto: 1,
    })),
}));

import AdminHome from './AdminHome.jsx';

// O rótulo mora dentro do card; sobe até a caixa do card (a que tem a sombra).
const card = (rotulo) => screen.getByText(rotulo).closest('.fetec-card-shadow');

describe('AdminHome — aba Projetos', () => {
    it('mostra só os seis cards da aba', async () => {
        render(<AdminHome />);

        expect(await screen.findByText('Projetos (total)')).toBeInTheDocument();
        expect(screen.getByText('Projetos por status')).toBeInTheDocument();
        expect(screen.getByText('Projetos por categoria')).toBeInTheDocument();
        expect(screen.getByText('Escolas com projeto')).toBeInTheDocument();
        expect(screen.getByText('Cidades com projeto')).toBeInTheDocument();
        expect(screen.getByText('Estados com projeto')).toBeInTheDocument();
    });

    it('não mostra mais os cards que foram para Dashboards', async () => {
        render(<AdminHome />);
        await screen.findByText('Projetos (total)');

        expect(screen.queryByText('Orientadores')).not.toBeInTheDocument();
        expect(screen.queryByText('Camisetas · Alunos')).not.toBeInTheDocument();
        expect(screen.queryByText('Alunos · Ensino Médio')).not.toBeInTheDocument();
        expect(screen.queryByText('Mulheres')).not.toBeInTheDocument();
    });

    it('o total de projetos leva à lista por área', async () => {
        render(<AdminHome />);
        await screen.findByText('Projetos (total)');

        expect(within(card('Projetos (total)')).getByText('Ver mais').closest('a'))
            .toHaveAttribute('href', '/admin/projetos-por-area');
    });

    it('mostra submetidos e rascunho no card de status', async () => {
        render(<AdminHome />);
        await screen.findByText('Projetos por status');

        const status = card('Projetos por status');
        expect(within(status).getByText('Submetidos')).toBeInTheDocument();
        expect(within(status).getByText('6')).toBeInTheDocument();
        expect(within(status).getByText('4')).toBeInTheDocument();
    });
});
