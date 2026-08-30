import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getProjetosPorArea = vi.fn(() => Promise.resolve([
    {
        area_id: 1,
        area: 'Ciências Agrárias',
        total: 2,
        submetidos: 1,
        rascunho: 1,
        projetos: [
            { id: 10, titulo: 'Zebra do cerrado', status: 'submetido', status_label: 'Submetido', categoria_label: 'FETECMS' },
            { id: 11, titulo: 'Abelha nativa', status: 'rascunho', status_label: 'Rascunho', categoria_label: 'FETEC Jr' },
        ],
    },
    {
        area_id: null,
        area: 'Área ainda não informada',
        total: 1,
        submetidos: 0,
        rascunho: 1,
        projetos: [{ id: 12, titulo: 'Sem área', status: 'rascunho', status_label: 'Rascunho', categoria_label: null }],
    },
]));
// A tela também pergunta o estado da janela de inscrição: é ela que decide se
// o botão "Projetos em rascunho" aparece.
const getInscricoesConfig = vi.fn(() => Promise.resolve({ encerradas: false }));
vi.mock('../lib/admin.js', () => ({
    getProjetosPorArea: (...a) => getProjetosPorArea(...a),
    getInscricoesConfig: (...a) => getInscricoesConfig(...a),
}));

import AdminProjetosPorArea from './AdminProjetosPorArea.jsx';

// O nome da área aparece duas vezes (card e cabeçalho do grupo).
const cabecalho = (area) => screen.getByRole('button', { name: new RegExp(area) });

describe('AdminProjetosPorArea', () => {
    it('mostra um card por área com submetidos e rascunhos', async () => {
        render(<AdminProjetosPorArea />);

        // Uma área com projeto = um card, inclusive a dos projetos sem área.
        expect(await screen.findAllByText('Ciências Agrárias')).toHaveLength(2);
        expect(screen.getAllByText('Área ainda não informada')).toHaveLength(2);
        expect(screen.getAllByText('Submetidos')).toHaveLength(2);
        // As pílulas de status só aparecem com o grupo aberto — aqui são só os cards.
        expect(screen.getAllByText('Rascunho')).toHaveLength(2);
    });

    it('começa com as áreas compactadas e abre ao clicar', async () => {
        render(<AdminProjetosPorArea />);

        await screen.findAllByText('Ciências Agrárias');
        expect(screen.queryByText('Zebra do cerrado')).not.toBeInTheDocument();

        fireEvent.click(cabecalho('Ciências Agrárias'));

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

    it('só oferece "Projetos em rascunho" depois do fim das inscrições', async () => {
        const { unmount } = render(<AdminProjetosPorArea />);
        await screen.findAllByText('Ciências Agrárias');
        expect(screen.queryByText('Projetos em rascunho')).not.toBeInTheDocument();
        unmount();

        getInscricoesConfig.mockResolvedValueOnce({ encerradas: true });
        render(<AdminProjetosPorArea />);
        expect(await screen.findByText('Projetos em rascunho')).toBeInTheDocument();
    });
});
