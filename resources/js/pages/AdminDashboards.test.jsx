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

import AdminDashboards from './AdminDashboards.jsx';

const card = (rotulo) => screen.getByText(rotulo).closest('.fetec-card-shadow');

describe('AdminDashboards — seções', () => {
    it('agrupa os cards por assunto', async () => {
        render(<AdminDashboards />);

        expect(await screen.findByText('Pessoas')).toBeInTheDocument();
        expect(screen.getByText('Camisetas')).toBeInTheDocument();
        expect(screen.getByText('Alunos por classe escolar')).toBeInTheDocument();
        expect(screen.getByText('Localidades')).toBeInTheDocument();
    });

    it('reúne todos os cards que a aba Projetos tinha', async () => {
        render(<AdminDashboards />);

        expect(await screen.findByText('Projetos (total)')).toBeInTheDocument();
        expect(screen.getByText('Projetos por status')).toBeInTheDocument();
        expect(screen.getByText('Projetos por categoria')).toBeInTheDocument();
        expect(screen.getAllByText('Mulheres')).toHaveLength(3);
        expect(screen.getByText('Camisetas · Alunos')).toBeInTheDocument();
        expect(screen.getByText('Alunos · Ensino Médio')).toBeInTheDocument();
        expect(screen.getByText('Escolas com projeto')).toBeInTheDocument();
    });

    // A pedido: aqui o total é só número — a lista mora na aba Projetos.
    it('o card de total de projetos NÃO tem o atalho "Ver mais"', async () => {
        render(<AdminDashboards />);

        await screen.findByText('Projetos (total)');

        expect(within(card('Projetos (total)')).queryByText('Ver mais')).not.toBeInTheDocument();
    });

    it('os cards de localidade continuam levando às listas', async () => {
        render(<AdminDashboards />);
        await screen.findByText('Escolas com projeto');

        expect(within(card('Escolas com projeto')).getByText('Ver mais').closest('a'))
            .toHaveAttribute('href', '/admin/projetos-por-escola');
    });

    it('as camisetas seguem sem o balde N.I.', async () => {
        render(<AdminDashboards />);
        await screen.findByText('Camisetas · Alunos');

        expect(screen.getAllByText('PP')).toHaveLength(3);
        expect(screen.queryByText('N.I.')).not.toBeInTheDocument();
    });
});
