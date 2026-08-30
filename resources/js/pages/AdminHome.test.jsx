import { render, screen, within } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
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
            { tamanho: 'N.I.', total: 0 },
        ] },
        alunos_camisetas: { total: 20, tamanhos: [
            { tamanho: 'PP', total: 4 }, { tamanho: 'P', total: 6 }, { tamanho: 'M', total: 5 },
            { tamanho: 'G', total: 3 }, { tamanho: 'GG', total: 1 }, { tamanho: 'XG', total: 0 },
            { tamanho: 'N.I.', total: 1 },
        ] },
        coorientadores_camisetas: { total: 3, tamanhos: [
            { tamanho: 'PP', total: 0 }, { tamanho: 'P', total: 0 }, { tamanho: 'M', total: 2 },
            { tamanho: 'G', total: 1 }, { tamanho: 'GG', total: 0 }, { tamanho: 'XG', total: 0 },
            { tamanho: 'N.I.', total: 0 },
        ] },
        alunos_classes: [
            { chave: 'fundamental_i', label: 'Ensino Fundamental I', total: 3, series: [
                { serie: '3º ano', total: 2 }, { serie: '4º ano', total: 0 },
                { serie: '5º ano', total: 1 }, { serie: 'N.I.', total: 0 },
            ] },
            { chave: 'fundamental_ii', label: 'Ensino Fundamental II', total: 5, series: [
                { serie: '6º ano', total: 1 }, { serie: '7º ano', total: 1 },
                { serie: '8º ano', total: 1 }, { serie: '9º ano', total: 1 },
                { serie: 'N.I.', total: 1 },
            ] },
            { chave: 'medio', label: 'Ensino Médio', total: 12, series: [
                { serie: '1º ano', total: 5 }, { serie: '2º ano', total: 4 },
                { serie: '3º ano', total: 2 }, { serie: '4º ano', total: 1 },
                { serie: 'N.I.', total: 0 },
            ] },
        ],
        escolas_com_projeto: 2, cidades_com_projeto: 2, estados_com_projeto: 1,
    })),
}));

import AdminHome from './AdminHome.jsx';

// O rótulo é a última linha do card; o card é o elemento que o contém.
const card = (rotulo) => screen.getByText(rotulo).parentElement;

describe('AdminHome — recorte por gênero', () => {
    it('mostra Mulheres/Homens/Outros nos 3 cards de pessoas', async () => {
        render(<AdminHome />);
        expect(await screen.findAllByText('Mulheres')).toHaveLength(3);
        expect(screen.getAllByText('Homens')).toHaveLength(3);
        expect(screen.getAllByText('Outros/N.I.')).toHaveLength(3);
        // números do card de alunos (11 mulheres, 8 homens)
        expect(screen.getByText('11')).toBeInTheDocument();
        expect(screen.getByText('8')).toBeInTheDocument();
    });
});

describe('AdminHome — projetos por categoria', () => {
    it('mostra uma coluna por categoria com a contagem', async () => {
        render(<AdminHome />);
        expect(await screen.findByText('Projetos por categoria')).toBeInTheDocument();
        expect(screen.getByText('FETEC Jr')).toBeInTheDocument();
        expect(screen.getByText('FETECMS')).toBeInTheDocument();
        expect(screen.getByText('FETECMS FUNDECT')).toBeInTheDocument();
        expect(screen.getByText('7')).toBeInTheDocument();
    });

    it('vem logo antes do card de orientadores', async () => {
        render(<AdminHome />);
        const categoria = await screen.findByText('Projetos por categoria');
        const orientadores = screen.getByText('Orientadores');
        // Ordem no DOM: categoria precede orientadores.
        expect(categoria.compareDocumentPosition(orientadores) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
});

describe('AdminHome — camisetas', () => {
    it('tem um card por público, com todos os tamanhos', async () => {
        render(<AdminHome />);
        expect(await screen.findByText('Camisetas · Orientadores')).toBeInTheDocument();
        expect(screen.getByText('Camisetas · Alunos')).toBeInTheDocument();
        expect(screen.getByText('Camisetas · Coorientadores')).toBeInTheDocument();
        // Sete baldes (PP…XG + N.I.) em cada um dos três cards.
        expect(screen.getAllByText('PP')).toHaveLength(3);
        expect(screen.getAllByText('XG')).toHaveLength(3);
        for (const publico of ['Orientadores', 'Alunos', 'Coorientadores']) {
            expect(within(card(`Camisetas · ${publico}`)).getByText('N.I.')).toBeInTheDocument();
        }
    });
});

describe('AdminHome — alunos por classe escolar', () => {
    it('tem um card por classe, com a quebra por série', async () => {
        render(<AdminHome />);
        expect(await screen.findByText('Alunos · Ensino Fundamental I')).toBeInTheDocument();
        expect(screen.getByText('Alunos · Ensino Fundamental II')).toBeInTheDocument();
        expect(screen.getByText('Alunos · Ensino Médio')).toBeInTheDocument();

        // As séries do médio vão até o 4º ano — é por onde entra o técnico integrado.
        const medio = card('Alunos · Ensino Médio');
        expect(within(medio).getByText('4º ano')).toBeInTheDocument();
        expect(within(medio).getByText('12')).toBeInTheDocument();

        // O Fundamental I vai do 3º ao 5º ano e tem seu próprio balde N.I.
        const fund1 = card('Alunos · Ensino Fundamental I');
        expect(within(fund1).getByText('5º ano')).toBeInTheDocument();
        expect(within(fund1).getByText('N.I.')).toBeInTheDocument();
    });
});
