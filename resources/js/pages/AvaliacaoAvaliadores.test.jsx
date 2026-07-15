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
                { id: 1, nome: 'Ana', em_avaliacao: 1, avaliou: 2, faltam: 1, limite: 2, is_demo: true },
                { id: 2, nome: 'Bruno', em_avaliacao: 0, avaliou: 0, faltam: 3, limite: null, is_demo: false },
            ],
        },
    ])),
    definirLimiteAvaliador: vi.fn(() => Promise.resolve({ meta: { message: 'ok' } })),
    definirDemoAvaliador: vi.fn(() => Promise.resolve({ meta: { message: 'ok' } })),
    limparDadosDeTeste: vi.fn(() => Promise.resolve({ meta: { message: '0 apagadas' } })),
}));

import AvaliacaoAvaliadores from './AvaliacaoAvaliadores.jsx';

describe('AvaliacaoAvaliadores', () => {
    it('lista com métricas, limite e marca demo', async () => {
        render(<AvaliacaoAvaliadores />);
        expect(await screen.findByText('Ana')).toBeInTheDocument();
        expect(screen.getByText('Limite 2')).toBeInTheDocument();
        expect(screen.getByText('Demo')).toBeInTheDocument();
        expect(screen.getByText('Limpar dados de teste')).toBeInTheDocument();
    });

    it('abre o modal de limitar avaliador', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');
        fireEvent.click(screen.getAllByTitle('Limitar avaliador')[0]);
        expect(await screen.findByText('Limitar avaliador')).toBeInTheDocument();
    });
});
