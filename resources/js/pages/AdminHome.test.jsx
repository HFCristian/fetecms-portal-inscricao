import { render, screen } from '@testing-library/react';
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
        escolas_com_projeto: 2, cidades_com_projeto: 2, estados_com_projeto: 1,
    })),
}));

import AdminHome from './AdminHome.jsx';

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
