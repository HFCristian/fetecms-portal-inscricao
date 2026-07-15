import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([])),
    loadSubareas: vi.fn(() => Promise.resolve([])),
}));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoProjetos: vi.fn(() => Promise.resolve([
        {
            area_id: 1,
            area: 'Área A',
            projetos: [
                { id: 1, titulo: 'Projeto X', realizadas: 2, em_avaliacao: 1, faltantes: 1 },
                { id: 2, titulo: 'Projeto Y', realizadas: 0, em_avaliacao: 0, faltantes: 3 },
            ],
        },
    ])),
    getAvaliacaoAvaliadores: vi.fn(() => Promise.resolve([])),
    designarProjeto: vi.fn(() => Promise.resolve({ data: { designadas: 1 }, meta: { message: '1 designação criada.' } })),
}));

import AvaliacaoProjetos from './AvaliacaoProjetos.jsx';

describe('AvaliacaoProjetos', () => {
    it('mostra as 3 métricas por projeto', async () => {
        render(<AvaliacaoProjetos />);
        expect(await screen.findByText('Projeto X')).toBeInTheDocument();
        expect(screen.getByText('Projeto Y')).toBeInTheDocument();
        expect(screen.getAllByText('Realizadas').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Em avaliação').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Faltantes').length).toBeGreaterThan(0);
    });

    it('abre o modal de designação ao clicar em Designar', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');
        fireEvent.click(screen.getAllByText('Designar')[0]);
        expect(await screen.findByText('Designar avaliação')).toBeInTheDocument();
        expect(screen.getByText('Um avaliador específico')).toBeInTheDocument();
    });
});
