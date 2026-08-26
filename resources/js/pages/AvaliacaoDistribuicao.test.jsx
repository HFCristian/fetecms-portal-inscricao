import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const redistribuirAvaliacoes = vi.fn(() => Promise.resolve({
    data: { devolvidas: 2, recebidas: 2, designadas_criadas: 2, ignorados_pela_regra: 0, sub_cobertos: [] },
    meta: { message: '2 designação(ões) trocadas por 2 nova(s).' },
}));

vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: vi.fn(() => Promise.resolve({
        liberada: true, encerrada: false,
        liberada_em_label: '01/10/2026 08:00', encerrada_em_label: null,
        min_por_avaliador: 3, min_por_projeto: 3,
    })),
    getDistribuicaoConfig: vi.fn(() => Promise.resolve({
        regras: {
            fetec_jr: { ativa: true, min_concluidas: 0, max_concluidas: null },
            fetecms: { ativa: true, min_concluidas: 0, max_concluidas: null },
            fetecms_fundect: { ativa: true, min_concluidas: 0, max_concluidas: null },
        },
        categorias: [
            { value: 'fetec_jr', label: 'FETEC Jr' },
            { value: 'fetecms', label: 'FETECMS' },
            { value: 'fetecms_fundect', label: 'FETECMS FUNDECT' },
        ],
        max_concluidas: 50,
        ao_cadastrar: false,
    })),
    definirRegrasDistribuicao: vi.fn(),
    definirDistribuicaoAoCadastrar: vi.fn(),
    distribuirAvaliacoes: vi.fn(),
    redistribuirAvaliacoes: (...a) => redistribuirAvaliacoes(...a),
}));

import AvaliacaoDistribuicao from './AvaliacaoDistribuicao.jsx';

describe('AvaliacaoDistribuicao', () => {
    it('reúne regras, toggle e as duas ações de distribuição', async () => {
        render(<AvaliacaoDistribuicao />);

        // Uma regra por categoria.
        expect(await screen.findByRole('switch', { name: /FETEC Jr/ })).toBeInTheDocument();
        expect(screen.getByLabelText('Máximo de avaliações recebidas — FETECMS')).toHaveValue(null);
        expect(screen.getByRole('switch', { name: 'Designar projetos ao cadastrar um avaliador' }))
            .toHaveAttribute('aria-checked', 'false');
        expect(screen.getByRole('button', { name: /Distribuir avaliações/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Redistribuir avaliações/ })).toBeInTheDocument();
        // Volta para a landing da aba.
        expect(screen.getByText('Avaliação online').closest('a')).toHaveAttribute('href', '/admin/avaliacao');
    });

    it('redistribui depois de confirmar e mostra o resultado', async () => {
        render(<AvaliacaoDistribuicao />);

        fireEvent.click(await screen.findByRole('button', { name: /Redistribuir avaliações/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Redistribuir' }));

        await waitFor(() => expect(redistribuirAvaliacoes).toHaveBeenCalled());
        expect(await screen.findByText('2 designação(ões) trocadas por 2 nova(s).')).toBeInTheDocument();
        expect(screen.getByText(/2 designação\(ões\) devolvidas ao bolo/)).toBeInTheDocument();
    });
});
