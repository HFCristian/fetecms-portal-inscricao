import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoProjetos: vi.fn(() => Promise.resolve([
        {
            area_id: 1,
            area: 'Área A',
            projetos: [
                { id: 1, titulo: 'Projeto X', avaliacoes_recebidas: 2 },
                { id: 2, titulo: 'Projeto Y', avaliacoes_recebidas: 0 },
            ],
        },
    ])),
}));

import AvaliacaoProjetos from './AvaliacaoProjetos.jsx';

describe('AvaliacaoProjetos', () => {
    it('lista projetos submetidos por área com as avaliações recebidas', async () => {
        render(<AvaliacaoProjetos />);
        expect(await screen.findByText('Projeto X')).toBeInTheDocument();
        expect(screen.getByText('Projeto Y')).toBeInTheDocument();
        expect(screen.getByText('2 avaliações')).toBeInTheDocument();
        expect(screen.getByText('0 avaliações')).toBeInTheDocument();
    });
});
