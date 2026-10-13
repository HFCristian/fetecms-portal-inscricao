import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? '', fields: {} }),
}));

const getRecomendacoes = vi.fn();
const decidirRecomendacao = vi.fn();
vi.mock('../lib/recomendacoes.js', () => ({
    getRecomendacoes: (...a) => getRecomendacoes(...a),
    decidirRecomendacao: (...a) => decidirRecomendacao(...a),
}));

import RecomendacoesPremiacao from './RecomendacoesPremiacao.jsx';

const PENDENTE = {
    id: 5, credencial_id: 1, projeto_id: 7, projeto: 'Bioplástico de mandioca', categoria: 'FETECMS', area: 'Ciências Agrárias',
    justificativa: 'Estande impecável.', status: 'pendente', status_label: 'Aguardando a organização',
    criada_em: '2026-10-13T10:00:00-04:00', credencial: 'MOSTRATEC 2027', credencial_tipo: 'credencial',
    credencial_tipo_label: 'Credencial', avaliador: 'Ana Comissão', decidida_por: null, decidida_em: null, ja_premiado: false,
};

const resposta = (recomendacoes, contagem = { pendente: 1, aprovada: 0, rejeitada: 0 }) => ({ recomendacoes, contagem });

describe('RecomendacoesPremiacao', () => {
    beforeEach(() => {
        getRecomendacoes.mockReset().mockResolvedValue(resposta([PENDENTE]));
        decidirRecomendacao.mockReset().mockResolvedValue({});
    });

    it('abre nas pendentes, com quem recomendou e a justificativa', async () => {
        render(<RecomendacoesPremiacao />);

        expect(await screen.findByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(getRecomendacoes).toHaveBeenCalledWith('pendente');
        expect(screen.getByText(/recomendado por Ana Comissão/)).toBeInTheDocument();
        expect(screen.getByText('Estande impecável.')).toBeInTheDocument();
        expect(screen.getByText('1 pendente(s)')).toBeInTheDocument();
    });

    it('aprova e recarrega a lista', async () => {
        render(<RecomendacoesPremiacao />);
        await screen.findByText('Bioplástico de mandioca');

        fireEvent.click(screen.getByRole('button', { name: 'Aprovar' }));

        await waitFor(() => expect(decidirRecomendacao).toHaveBeenCalledWith(5, 'aprovada'));
        await waitFor(() => expect(getRecomendacoes).toHaveBeenCalledTimes(2));
    });

    it('rejeita', async () => {
        render(<RecomendacoesPremiacao />);
        await screen.findByText('Bioplástico de mandioca');

        fireEvent.click(screen.getByRole('button', { name: 'Rejeitar' }));

        await waitFor(() => expect(decidirRecomendacao).toHaveBeenCalledWith(5, 'rejeitada'));
    });

    it('a decidida mostra quem decidiu e só oferece trocar a decisão', async () => {
        getRecomendacoes.mockResolvedValue(resposta([{
            ...PENDENTE, status: 'aprovada', status_label: 'Aprovada', decidida_por: 'Admin', decidida_em: '2026-10-13T11:00:00-04:00', ja_premiado: true,
        }], { pendente: 0, aprovada: 1, rejeitada: 0 }));
        render(<RecomendacoesPremiacao />);

        expect(await screen.findByText(/Aprovada por Admin/)).toBeInTheDocument();
        expect(screen.getByText('já recebeu esta credencial')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Aprovar' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Rejeitar' })).toBeInTheDocument();
    });

    it('troca o filtro', async () => {
        render(<RecomendacoesPremiacao />);
        await screen.findByText('Bioplástico de mandioca');

        fireEvent.click(screen.getByRole('button', { name: /Todas/ }));

        await waitFor(() => expect(getRecomendacoes).toHaveBeenLastCalledWith(null));
    });

    it('sem recomendação nenhuma diz isso', async () => {
        getRecomendacoes.mockResolvedValue(resposta([], { pendente: 0, aprovada: 0, rejeitada: 0 }));
        render(<RecomendacoesPremiacao />);

        expect(await screen.findByText('O comitê ainda não fez nenhuma recomendação.')).toBeInTheDocument();
    });
});
