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

const OPCOES = {
    areas: [{ id: 3, nome: 'Ciências Agrárias' }, { id: 4, nome: 'Ciências Biológicas' }],
    categorias: [{ value: 'fetec_jr', label: 'FETEC Jr' }, { value: 'fetecms', label: 'FETECMS' }, { value: 'fetecms_fundect', label: 'FETECMS FUNDECT' }],
};
const SEM_RECORTE = { q: '', area_id: '', categoria: '' };
const resposta = (recomendacoes, contagem = { pendente: 1, aprovada: 0, rejeitada: 0 }) => ({ recomendacoes, contagem, opcoes: OPCOES });

describe('RecomendacoesPremiacao', () => {
    beforeEach(() => {
        getRecomendacoes.mockReset().mockResolvedValue(resposta([PENDENTE]));
        decidirRecomendacao.mockReset().mockResolvedValue({});
    });

    it('abre nas pendentes, com quem recomendou e a justificativa', async () => {
        render(<RecomendacoesPremiacao />);

        expect(await screen.findByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(getRecomendacoes).toHaveBeenCalledWith('pendente', SEM_RECORTE);
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

        await waitFor(() => expect(getRecomendacoes).toHaveBeenLastCalledWith(null, SEM_RECORTE));
    });

    it('sem recomendação nenhuma diz isso', async () => {
        getRecomendacoes.mockResolvedValue(resposta([], { pendente: 0, aprovada: 0, rejeitada: 0 }));
        render(<RecomendacoesPremiacao />);

        expect(await screen.findByText('O comitê ainda não fez nenhuma recomendação.')).toBeInTheDocument();
    });

    // Sprint 194: o recorte pelo projeto.
    it('filtra por título, área e categoria e limpa', async () => {
        render(<RecomendacoesPremiacao />);
        await screen.findByText('Bioplástico de mandioca');

        fireEvent.change(screen.getByLabelText('Buscar pelo título do projeto'), { target: { value: ' mandioca ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Buscar' }));
        await waitFor(() => expect(getRecomendacoes).toHaveBeenLastCalledWith('pendente', { ...SEM_RECORTE, q: 'mandioca' }));

        fireEvent.change(screen.getByLabelText('Área do conhecimento'), { target: { value: '3' } });
        await waitFor(() => expect(getRecomendacoes).toHaveBeenLastCalledWith('pendente', { q: 'mandioca', area_id: '3', categoria: '' }));

        fireEvent.change(screen.getByLabelText('Categoria'), { target: { value: 'fetecms_fundect' } });
        await waitFor(() => expect(getRecomendacoes).toHaveBeenLastCalledWith('pendente', { q: 'mandioca', area_id: '3', categoria: 'fetecms_fundect' }));

        fireEvent.click(screen.getByRole('button', { name: 'Limpar filtros' }));
        await waitFor(() => expect(getRecomendacoes).toHaveBeenLastCalledWith('pendente', SEM_RECORTE));
        expect(screen.getByLabelText('Buscar pelo título do projeto')).toHaveValue('');
    });

    it('filtro sem resultado diz isso', async () => {
        getRecomendacoes.mockResolvedValue(resposta([], { pendente: 0, aprovada: 0, rejeitada: 0 }));
        render(<RecomendacoesPremiacao />);
        await screen.findByText('O comitê ainda não fez nenhuma recomendação.');

        fireEvent.change(screen.getByLabelText('Categoria'), { target: { value: 'fetecms' } });

        expect(await screen.findByText('Nenhuma recomendação com esses filtros.')).toBeInTheDocument();
    });

    it('avisa a página depois de decidir e recarrega quando a página muda', async () => {
        const onDecidido = vi.fn();
        const { rerender } = render(<RecomendacoesPremiacao atualizacao={0} onDecidido={onDecidido} />);
        await screen.findByText('Bioplástico de mandioca');

        fireEvent.click(screen.getByRole('button', { name: 'Aprovar' }));
        await waitFor(() => expect(onDecidido).toHaveBeenCalled());

        const chamadas = getRecomendacoes.mock.calls.length;
        rerender(<RecomendacoesPremiacao atualizacao={1} onDecidido={onDecidido} />);
        await waitFor(() => expect(getRecomendacoes.mock.calls.length).toBe(chamadas + 1));
    });
});
