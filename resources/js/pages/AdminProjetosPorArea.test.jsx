import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getProjetosPorArea = vi.fn(() => Promise.resolve({
    categorias: [
        { value: 'fetec_jr', label: 'FETEC Jr', submetidos: 3, rascunho: 1, total: 4 },
        { value: 'fetecms', label: 'FETECMS', submetidos: 7, rascunho: 2, total: 9 },
        { value: 'fetecms_fundect', label: 'FETECMS FUNDECT', submetidos: 0, rascunho: 0, total: 0 },
    ],
    areas: [
        {
            area_id: 1,
            area: 'Ciências Agrárias',
            total: 2,
            projetos: [
                { id: 10, titulo: 'Zebra do cerrado', status: 'submetido', status_label: 'Submetido', categoria_label: 'FETECMS' },
                { id: 11, titulo: 'Abelha nativa', status: 'rascunho', status_label: 'Rascunho', categoria_label: 'FETEC Jr' },
            ],
        },
        {
            area_id: null,
            area: 'Área ainda não informada',
            total: 1,
            projetos: [{ id: 12, titulo: 'Sem área', status: 'rascunho', status_label: 'Rascunho', categoria_label: null }],
        },
    ],
}));
vi.mock('../lib/admin.js', () => ({ getProjetosPorArea: (...a) => getProjetosPorArea(...a) }));

import AdminProjetosPorArea from './AdminProjetosPorArea.jsx';

describe('AdminProjetosPorArea', () => {
    it('mostra um card por categoria com submetidos e rascunhos', async () => {
        render(<AdminProjetosPorArea />);

        expect(await screen.findByText('FETEC Jr')).toBeInTheDocument();
        expect(screen.getByText('FETECMS FUNDECT')).toBeInTheDocument();
        expect(screen.getByText('7')).toBeInTheDocument(); // submetidos da FETECMS
        expect(screen.getAllByText('Submetidos')).toHaveLength(3);
    });

    it('começa com as áreas compactadas e abre ao clicar', async () => {
        render(<AdminProjetosPorArea />);

        expect(await screen.findByText('Ciências Agrárias')).toBeInTheDocument();
        expect(screen.queryByText('Zebra do cerrado')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Ciências Agrárias'));

        // Aberta, a lista sai em ordem alfabética de título.
        const titulos = screen.getAllByText(/Zebra do cerrado|Abelha nativa/).map((n) => n.textContent);
        expect(titulos).toEqual(['Abelha nativa', 'Zebra do cerrado']);
    });

    it('expande e recolhe todas as áreas de uma vez', async () => {
        render(<AdminProjetosPorArea />);

        fireEvent.click(await screen.findByText('Expandir todas'));
        expect(screen.getByText('Zebra do cerrado')).toBeInTheDocument();
        expect(screen.getByText('Sem área')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Recolher todas'));
        expect(screen.queryByText('Zebra do cerrado')).not.toBeInTheDocument();
    });
});
