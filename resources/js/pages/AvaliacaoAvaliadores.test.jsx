import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoAvaliadores: vi.fn(() => Promise.resolve([
        {
            area_id: 1,
            area: 'Área A',
            avaliadores: [
                { id: 1, nome: 'Ana', em_avaliacao: 1, avaliou: 2, faltam: 1 },
                { id: 2, nome: 'Bruno', em_avaliacao: 0, avaliou: 0, faltam: 3 },
            ],
        },
    ])),
}));

import AvaliacaoAvaliadores from './AvaliacaoAvaliadores.jsx';

describe('AvaliacaoAvaliadores', () => {
    it('lista avaliadores por área com as métricas', async () => {
        render(<AvaliacaoAvaliadores />);
        expect(await screen.findByText('Ana')).toBeInTheDocument();
        expect(screen.getByText('Bruno')).toBeInTheDocument();
        expect(screen.getByText('Área A')).toBeInTheDocument();
        expect(screen.getAllByText('Em avaliação').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Faltam').length).toBeGreaterThan(0);
    });
});
