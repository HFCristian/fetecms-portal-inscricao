import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getOpcoesDesignacaoComite = vi.fn();
const designarPeloComite = vi.fn();
vi.mock('../lib/comite.js', () => ({
    getOpcoesDesignacaoComite: (...a) => getOpcoesDesignacaoComite(...a),
    designarPeloComite: (...a) => designarPeloComite(...a),
}));

import ComiteDesignacoes from './ComiteDesignacoes.jsx';

const OPCOES = {
    projetos: [{ id: 1, titulo: 'Bioplástico', area: 'Exatas', categoria: 'FETECMS', concluidas: 0 }],
    avaliadores: [{ id: 5, nome: 'Ana Comissao', email: 'ana@fetec.test', area: 'Exatas', na_fila: 2 }],
};

describe('ComiteDesignacoes', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getOpcoesDesignacaoComite.mockResolvedValue(OPCOES);
        designarPeloComite.mockResolvedValue({
            data: { designadas: 1, ignoradas: [], resumo: '1 designação(ões) criada(s).' },
            meta: { message: '1 designação(ões) criada(s).' },
        });
    });

    it('só oferece avaliadores da comissão especial', async () => {
        render(<ComiteDesignacoes />);

        expect(await screen.findByText('Avaliadores da comissão especial')).toBeInTheDocument();
        // A busca é debounced: as listas chegam depois.
        expect(await screen.findByText('Ana Comissao')).toBeInTheDocument();
    });

    it('cruza os marcados e mostra o total antes de designar', async () => {
        render(<ComiteDesignacoes />);

        fireEvent.click(await screen.findByRole('checkbox', { name: 'Bioplástico' }));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Ana Comissao' }));

        expect(screen.getByText(/1 projeto\(s\) × 1 avaliador\(es\) =/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Designar/ }));

        await waitFor(() => expect(designarPeloComite).toHaveBeenCalledWith([1], [5]));
        expect(await screen.findByText('1 designação(ões) criada(s).')).toBeInTheDocument();
    });

    it('sem nada marcado não há o que designar', async () => {
        render(<ComiteDesignacoes />);

        await screen.findByText('Ana Comissao');
        expect(screen.getByRole('button', { name: /Designar/ })).toBeDisabled();
    });

    it('mostra quem ficou de fora e por quê', async () => {
        designarPeloComite.mockResolvedValue({
            data: {
                designadas: 0, resumo: '0 designação(ões) criada(s).',
                ignoradas: ['Bioplástico → Ana Comissao: já avaliou este projeto.'],
            },
            meta: {},
        });
        render(<ComiteDesignacoes />);

        fireEvent.click(await screen.findByRole('checkbox', { name: 'Bioplástico' }));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Ana Comissao' }));
        fireEvent.click(screen.getByRole('button', { name: /Designar/ }));

        expect(await screen.findByText(/já avaliou este projeto/)).toBeInTheDocument();
    });

    it('sem ninguém na comissão, explica onde marcar', async () => {
        getOpcoesDesignacaoComite.mockResolvedValue({ ...OPCOES, avaliadores: [] });
        render(<ComiteDesignacoes />);

        expect(await screen.findByText(/Nenhum avaliador da comissão especial/)).toBeInTheDocument();
    });
});
