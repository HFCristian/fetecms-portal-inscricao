import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: vi.fn(() => Promise.resolve({
        liberada: true, encerrada: false,
        liberada_em_input: '2026-10-01T08:00', liberada_em_label: '01/10/2026 08:00',
        encerrada_em_input: '2026-10-10T18:00', encerrada_em_label: '10/10/2026 18:00',
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
    redistribuirAvaliacoes: vi.fn(() => Promise.resolve({
        data: { devolvidas: 2, recebidas: 2, designadas_criadas: 2, ignorados_pela_regra: 0, sub_cobertos: [] },
        meta: { message: '2 designação(ões) trocadas por 2 nova(s).' },
    })),
    distribuirAvaliacoes: vi.fn(() => Promise.resolve({ data: { designadas_criadas: 0, sub_cobertos: [] }, meta: { message: '0 designações' } })),
}));

import AdminAvaliacaoOnline from './AdminAvaliacaoOnline.jsx';

describe('AdminAvaliacaoOnline', () => {
    it('mostra a janela do período, a distribuição e os cards de acesso', async () => {
        render(<AdminAvaliacaoOnline />);
        expect(screen.getByRole('heading', { name: 'Algoritmo de distribuição' })).toBeInTheDocument();
        expect(screen.getByText('Distribuição automática')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Distribuir avaliações/ })).toBeInTheDocument();
        // As datas são só um resumo: quem altera é a Parametrização.
        expect(await screen.findByText('Avaliação liberada — encerra em 10/10/2026 18:00.')).toBeInTheDocument();
        expect(screen.getByText('Alterar datas').closest('a')).toHaveAttribute('href', '/admin/parametrizacao/avaliacao');
        expect(screen.getByText('Avaliadores Online')).toBeInTheDocument();
        expect(screen.getByText('Projetos submetidos')).toBeInTheDocument();
    });

    it('desenha uma regra por categoria dentro da seção do algoritmo', async () => {
        render(<AdminAvaliacaoOnline />);
        // Toggle + faixa de avaliações concluídas de cada categoria.
        expect(await screen.findByRole('switch', { name: /FETEC Jr/ })).toBeInTheDocument();
        expect(screen.getByLabelText('Mínimo de avaliações concluídas — FETECMS FUNDECT')).toHaveValue(0);
        expect(screen.getByLabelText('Máximo de avaliações concluídas — FETECMS')).toHaveValue(null);
        expect(screen.getByRole('button', { name: 'Salvar regras' })).toBeInTheDocument();
    });

    it('traz o rodízio e o toggle de designação ao cadastrar', async () => {
        render(<AdminAvaliacaoOnline />);
        expect(screen.getByRole('button', { name: /Redistribuir avaliações/ })).toBeInTheDocument();
        expect(await screen.findByRole('switch', { name: 'Designar projetos ao cadastrar um avaliador' }))
            .toHaveAttribute('aria-checked', 'false');
    });
});
