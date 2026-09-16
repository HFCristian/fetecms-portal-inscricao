import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
}));

const getVerificacoesDisparidade = vi.fn();
const gerarVerificacaoDisparidade = vi.fn();
const getVerificacaoDisparidade = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getVerificacoesDisparidade: (...a) => getVerificacoesDisparidade(...a),
    gerarVerificacaoDisparidade: (...a) => gerarVerificacaoDisparidade(...a),
    getVerificacaoDisparidade: (...a) => getVerificacaoDisparidade(...a),
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? '', fields: e?.fields ?? {} }),
}));

import AvaliacaoDisparidade from './AvaliacaoDisparidade.jsx';

const VERIFICACAO = {
    id: 7,
    diferenca: 2,
    total: 1,
    autor: 'Admin',
    criada_em: '2026-09-15T10:00:00-04:00',
    nota_maxima: 10,
    itens: [
        {
            projeto_id: 42,
            titulo: 'Secador solar',
            area: 'Exatas',
            categoria: 'FETECMS',
            avaliacoes: 3,
            nota_min: 4.5,
            nota_max: 9.5,
            amplitude: 5,
            media: 7,
        },
    ],
};

describe('AvaliacaoDisparidade', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getVerificacoesDisparidade.mockResolvedValue([]);
        gerarVerificacaoDisparidade.mockResolvedValue(VERIFICACAO);
        getVerificacaoDisparidade.mockResolvedValue(VERIFICACAO);
    });

    it('gera a lista com a diferença digitada, em vírgula, e mostra as notas', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.change(screen.getByLabelText(/Diferença entre as notas/i), { target: { value: '2,50' } });
        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));

        // O admin digita com vírgula; a API recebe número.
        await waitFor(() => expect(gerarVerificacaoDisparidade).toHaveBeenCalledWith(2.5));

        expect(await screen.findByText('Secador solar')).toBeInTheDocument();
        expect(screen.getByText('4,50 — 9,50')).toBeInTheDocument();
        expect(screen.getByText('5,00')).toBeInTheDocument();
    });

    it('cada projeto leva ao diálogo de designação já com ele marcado', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));

        const link = await screen.findByRole('link', { name: /Designar/i });
        expect(link.getAttribute('href')).toBe(
            '/admin/avaliacao/designacoes?projeto=42&q=Secador%20solar',
        );
    });

    it('lista vazia diz que ninguém passou do corte', async () => {
        gerarVerificacaoDisparidade.mockResolvedValue({ ...VERIFICACAO, total: 0, itens: [] });
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));

        expect(await screen.findByText(/Nenhum projeto com essa diferença/i)).toBeInTheDocument();
    });

    it('abre uma verificação anterior do histórico', async () => {
        getVerificacoesDisparidade.mockResolvedValue([
            { id: 7, diferenca: 2, total: 1, autor: 'Admin', criada_em: '2026-09-15T10:00:00-04:00' },
        ]);
        render(<AvaliacaoDisparidade />);

        fireEvent.click(await screen.findByRole('button', { name: /Abrir/i }));

        await waitFor(() => expect(getVerificacaoDisparidade).toHaveBeenCalledWith(7));
        expect(await screen.findByText('Secador solar')).toBeInTheDocument();
    });

    it('mostra o erro do servidor sem apagar o formulário', async () => {
        gerarVerificacaoDisparidade.mockRejectedValue({ fields: { diferenca: 'A diferença é obrigatória.' } });
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));

        expect(await screen.findByText('A diferença é obrigatória.')).toBeInTheDocument();
        expect(screen.getByLabelText(/Diferença entre as notas/i)).toBeInTheDocument();
    });
});
