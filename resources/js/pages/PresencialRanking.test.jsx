import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: (e) => ({ message: e?.message ?? 'Erro.' }) }));

const RANKING = {
    lista: { id: 1, nome: 'Final', demo: false },
    vagas: { comuns: 2, comite: 1, peso_comite: 2 },
    modo_teste: false,
    is_demo: false,
    opcoes: {
        areas: [{ id: 3, nome: 'Ciências Agrárias' }],
        categorias: [{ value: 'fetecms', label: 'FETECMS' }],
    },
    projetos: [
        {
            posicao: 1, projeto_id: 10, codigo: 'FET.AGR-001', titulo: 'Abelhas', area: 'Ciências Agrárias', categoria: 'FETECMS',
            estande: 12, turno_label: 'Turno A (matutino)', media: 8, avaliacoes: 3, comuns: 2, comite: 1, parcial: false,
            notas: [{ nota: 8.5, vaga: 'comum', peso: 1 }, { nota: 5.5, vaga: 'comum', peso: 1 }, { nota: 9, vaga: 'comite', peso: 2 }],
        },
        {
            posicao: 2, projeto_id: 11, codigo: null, titulo: 'Compostagem', area: 'Ciências Agrárias', categoria: 'FETECMS',
            estande: null, turno_label: null, media: 7.25, avaliacoes: 1, comuns: 1, comite: 0, parcial: true,
            notas: [{ nota: 7.25, vaga: 'comum', peso: 1 }],
        },
    ],
};

const getRankingPresencial = vi.fn(() => Promise.resolve(RANKING));
vi.mock('../lib/presencial.js', () => ({
    getRankingPresencial: (...a) => getRankingPresencial(...a),
}));

import PresencialRanking from './PresencialRanking.jsx';

describe('PresencialRanking', () => {
    beforeEach(() => vi.clearAllMocks());

    it('mostra a média ponderada, as notas com a da comissão em destaque e o parcial', async () => {
        render(<PresencialRanking />);

        expect(await screen.findByText('Abelhas')).toBeInTheDocument();
        expect(screen.getByText('FET.AGR-001')).toBeInTheDocument();
        expect(screen.getByText('8,000')).toBeInTheDocument();
        expect(screen.getByText('9,000 (comissão ×2)')).toBeInTheDocument();
        expect(screen.getByText(/Estande 012 · Turno A/)).toBeInTheDocument();
        expect(screen.getByText(/a nota da comissão pesa/)).toHaveTextContent('pesa 2');
        expect(screen.getByText(/1 projeto ainda não teve todas as/)).toBeInTheDocument();
        expect(screen.getByText('Compostagem').closest('li')).toHaveTextContent('parcial');
        expect(screen.getByText('Abelhas').closest('li')).not.toHaveTextContent('parcial');
    });

    it('filtra por área e categoria no servidor', async () => {
        render(<PresencialRanking />);
        await screen.findByText('Abelhas');

        fireEvent.change(screen.getByLabelText('Área do conhecimento'), { target: { value: '3' } });
        await waitFor(() => expect(getRankingPresencial).toHaveBeenLastCalledWith({ area_id: '3', categoria: '' }, false));

        fireEvent.change(screen.getByLabelText('Categoria'), { target: { value: 'fetecms' } });
        await waitFor(() => expect(getRankingPresencial).toHaveBeenLastCalledWith({ area_id: '3', categoria: 'fetecms' }, false));
    });

    it('sem avaliação enviada, diz que não há ranking ainda', async () => {
        getRankingPresencial.mockResolvedValueOnce({ ...RANKING, projetos: [] });
        render(<PresencialRanking />);

        expect(await screen.findByText('Nenhum projeto com avaliação presencial enviada.')).toBeInTheDocument();
    });
});
