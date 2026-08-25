import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));

const arvore = [{
    id: 7, nome: 'Ciências Agrárias', usos: 2,
    grupo_correlato: null, grupo_correlato_label: null, subareas: [],
}];

const definirCorrelacaoArea = vi.fn(() => Promise.resolve([{ ...arvore[0], grupo_correlato: 'vida' }]));

vi.mock('../lib/admin.js', () => ({
    getCatalogo: vi.fn(() => Promise.resolve({
        areas: [{ id: 7, nome: 'Ciências Agrárias', usos: 2, grupo_correlato: null, grupo_correlato_label: null, subareas: [] }],
        grupos: [
            { value: 'vida', label: 'Ciências da vida', descricao: 'Agrárias, Biológicas e Saúde' },
            { value: 'exatas_engenharias', label: 'Exatas e engenharias', descricao: 'Engenharias e Exatas' },
        ],
    })),
    renomearArea: vi.fn(),
    mesclarArea: vi.fn(),
    excluirArea: vi.fn(),
    definirCorrelacaoArea: (...args) => definirCorrelacaoArea(...args),
    renomearSubarea: vi.fn(),
    mesclarSubarea: vi.fn(),
    excluirSubarea: vi.fn(),
}));

import ParametrizacaoAreas from './ParametrizacaoAreas.jsx';

describe('ParametrizacaoAreas — áreas correlatas', () => {
    it('mostra o seletor de correlação com as opções do servidor', async () => {
        render(<ParametrizacaoAreas />);

        const select = await screen.findByRole('combobox', { name: 'Áreas correlatas de Ciências Agrárias' });
        expect(select).toHaveValue('');
        expect(screen.getByRole('option', { name: 'Sem correlação' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Ciências da vida — Agrárias, Biológicas e Saúde' })).toBeInTheDocument();
    });

    it('salva o grupo escolhido', async () => {
        render(<ParametrizacaoAreas />);

        const select = await screen.findByRole('combobox', { name: 'Áreas correlatas de Ciências Agrárias' });
        fireEvent.change(select, { target: { value: 'vida' } });

        await waitFor(() => expect(definirCorrelacaoArea).toHaveBeenCalledWith(7, 'vida'));
        expect(await screen.findByText('Áreas correlatas atualizadas.')).toBeInTheDocument();
    });
});
