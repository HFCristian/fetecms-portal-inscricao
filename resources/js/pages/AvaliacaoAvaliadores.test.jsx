import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoAvaliadores: vi.fn(() => Promise.resolve([
        {
            area_id: 1,
            area: 'Área A',
            avaliadores: [
                { id: 1, nome: 'Ana', em_avaliacao: 1, avaliou: 2, faltam: 1, limite: 2 },
                { id: 2, nome: 'Bruno', em_avaliacao: 0, avaliou: 0, faltam: 3, limite: null },
            ],
        },
    ])),
    definirLimiteAvaliador: vi.fn(() => Promise.resolve({ meta: { message: 'Limite definido em 3.' } })),
}));

import AvaliacaoAvaliadores from './AvaliacaoAvaliadores.jsx';

describe('AvaliacaoAvaliadores', () => {
    it('lista avaliadores com métricas e exibe o limite', async () => {
        render(<AvaliacaoAvaliadores />);
        expect(await screen.findByText('Ana')).toBeInTheDocument();
        expect(screen.getByText('Bruno')).toBeInTheDocument();
        expect(screen.getByText('Limite 2')).toBeInTheDocument();
        expect(screen.getAllByText('Em avaliação').length).toBeGreaterThan(0);
    });

    it('abre o modal de limitar avaliador', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');
        fireEvent.click(screen.getAllByTitle('Limitar avaliador')[0]);
        expect(await screen.findByText('Limitar avaliador')).toBeInTheDocument();
        expect(screen.getByText('Máximo de avaliações que pode assumir')).toBeInTheDocument();
    });
});
