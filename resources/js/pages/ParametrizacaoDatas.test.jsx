import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));

const getInscricoesConfig = vi.fn();
const definirInicioInscricoes = vi.fn();
const definirPrazoInscricoes = vi.fn();
const getAvaliacaoConfig = vi.fn();
const definirLiberacaoAvaliacao = vi.fn();
const definirEncerramentoAvaliacao = vi.fn();
const definirInicioAjustes = vi.fn();
const definirFimAjustes = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getInscricoesConfig: (...a) => getInscricoesConfig(...a),
    definirInicioInscricoes: (...a) => definirInicioInscricoes(...a),
    definirPrazoInscricoes: (...a) => definirPrazoInscricoes(...a),
    getAvaliacaoConfig: (...a) => getAvaliacaoConfig(...a),
    definirLiberacaoAvaliacao: (...a) => definirLiberacaoAvaliacao(...a),
    definirEncerramentoAvaliacao: (...a) => definirEncerramentoAvaliacao(...a),
    definirInicioAjustes: (...a) => definirInicioAjustes(...a),
    definirFimAjustes: (...a) => definirFimAjustes(...a),
}));

const getParametrizacaoCredenciamento = vi.fn();
const definirJanelaEvento = vi.fn();
vi.mock('../lib/credenciamento.js', () => ({
    getParametrizacaoCredenciamento: (...a) => getParametrizacaoCredenciamento(...a),
    definirJanelaEvento: (...a) => definirJanelaEvento(...a),
}));

import ParametrizacaoDatas from './ParametrizacaoDatas.jsx';

const inscricoes = {
    abertas: true, encerradas: false, nao_iniciadas: false,
    inicio_input: null, inicio_label: null, prazo_input: null, prazo_label: null,
};
const avaliacao = {
    liberada: false, encerrada: false,
    liberada_em_input: null, liberada_em_label: null,
    encerrada_em_input: null, encerrada_em_label: null,
    ajustes_abertos: false, ajustes_de_input: null, ajustes_de_label: null,
    ajustes_ate_input: null, ajustes_ate_label: null,
};

describe('ParametrizacaoDatas', () => {
    beforeEach(() => {
        getInscricoesConfig.mockReset().mockResolvedValue(inscricoes);
        getAvaliacaoConfig.mockReset().mockResolvedValue(avaliacao);
        getParametrizacaoCredenciamento.mockReset().mockResolvedValue({
            config: { inicio_input: '2026-11-25T08:00', fim_input: '' },
        });
        definirInicioInscricoes.mockReset();
        definirLiberacaoAvaliacao.mockReset();
        definirInicioAjustes.mockReset();
        definirFimAjustes.mockReset();
        definirJanelaEvento.mockReset();
    });

    it('reúne as quatro janelas da edição numa tela só', async () => {
        render(<ParametrizacaoDatas />);

        expect(await screen.findByText('Inscrições')).toBeInTheDocument();
        expect(screen.getByText('Avaliação online')).toBeInTheDocument();
        expect(screen.getByText('Ajustes do orientador')).toBeInTheDocument();
        expect(screen.getByText('Credenciamento (evento)')).toBeInTheDocument();

        // As oito pontas de data estão todas aqui.
        expect(screen.getByLabelText('Data de abertura das inscrições')).toBeInTheDocument();
        expect(screen.getByLabelText('Data-limite de submissão')).toBeInTheDocument();
        expect(screen.getByLabelText('Data de liberação da avaliação')).toBeInTheDocument();
        expect(screen.getByLabelText('Data de encerramento da avaliação')).toBeInTheDocument();
        expect(screen.getByLabelText('Data de início do período de ajustes')).toBeInTheDocument();
        expect(screen.getByLabelText('Data de fim do período de ajustes')).toBeInTheDocument();
        expect(screen.getByLabelText('Início do período do evento')).toBeInTheDocument();
        expect(screen.getByLabelText('Fim do período do evento')).toBeInTheDocument();
    });

    it('salva a abertura das inscrições', async () => {
        definirInicioInscricoes.mockResolvedValue({
            data: { ...inscricoes, inicio_input: '2026-09-01T08:00', inicio_label: '01/09/2026 08:00', nao_iniciadas: true },
            meta: { message: 'Abertura salva.' },
        });
        render(<ParametrizacaoDatas />);

        fireEvent.change(await screen.findByLabelText('Data de abertura das inscrições'), {
            target: { value: '2026-09-01T08:00' },
        });
        fireEvent.click(screen.getByText('Salvar abertura'));

        await waitFor(() => expect(definirInicioInscricoes).toHaveBeenCalledWith('2026-09-01T08:00'));
        expect(await screen.findByText('Abertura salva.')).toBeInTheDocument();
        expect(screen.getByText('Abre em 01/09/2026 08:00')).toBeInTheDocument();
    });

    // Regressão do erro relatado em produção: o card repassa `resp.data` ao
    // `setConfig`, então o helper precisa devolver o envelope inteiro.
    it('salva as duas pontas do período de ajustes sem derrubar a tela', async () => {
        definirInicioAjustes.mockResolvedValue({
            data: { ...avaliacao, ajustes_de_input: '2026-11-01T08:00', ajustes_de_label: '01/11/2026 08:00' },
            meta: { message: 'Período de ajustes atualizado.' },
        });
        definirFimAjustes.mockResolvedValue({
            data: { ...avaliacao, ajustes_ate_input: '2026-11-20T18:00', ajustes_ate_label: '20/11/2026 18:00' },
            meta: { message: 'Período de ajustes atualizado.' },
        });
        render(<ParametrizacaoDatas />);

        fireEvent.change(await screen.findByLabelText('Data de início do período de ajustes'), {
            target: { value: '2026-11-01T08:00' },
        });
        fireEvent.click(screen.getByText('Salvar início dos ajustes'));

        await waitFor(() => expect(definirInicioAjustes).toHaveBeenCalledWith('2026-11-01T08:00'));
        expect(await screen.findByText('Abre em 01/11/2026 08:00')).toBeInTheDocument();
        // A tela continua de pé: os outros campos seguem renderizados.
        expect(screen.getByLabelText('Data de liberação da avaliação')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Data de fim do período de ajustes'), {
            target: { value: '2026-11-20T18:00' },
        });
        fireEvent.click(screen.getByText('Salvar fim dos ajustes'));

        await waitFor(() => expect(definirFimAjustes).toHaveBeenCalledWith('2026-11-20T18:00'));
        expect(await screen.findByText('Ajustes até 20/11/2026 18:00')).toBeInTheDocument();
    });

    it('sem início, avisa que a aba de ajustes fica fechada', async () => {
        render(<ParametrizacaoDatas />);

        expect(await screen.findByText('Aba fechada')).toBeInTheDocument();
    });

    it('salva o período do evento com as duas pontas de uma vez', async () => {
        definirJanelaEvento.mockResolvedValue({
            config: { inicio_input: '2026-11-25T08:00', fim_input: '2026-11-27T18:00' },
        });
        render(<ParametrizacaoDatas />);

        const fim = await screen.findByLabelText('Fim do período do evento');
        fireEvent.change(fim, { target: { value: '2026-11-27T18:00' } });
        fireEvent.click(screen.getByText('Salvar período'));

        await waitFor(() => expect(definirJanelaEvento)
            .toHaveBeenCalledWith('2026-11-25T08:00', '2026-11-27T18:00'));
        expect(await screen.findByText('Período do evento salvo.')).toBeInTheDocument();
    });

    it('mostra o erro de ordem vindo do backend', async () => {
        definirLiberacaoAvaliacao.mockRejectedValue({
            response: { status: 422, data: { message: 'A liberação precisa ser antes do encerramento.' } },
        });
        render(<ParametrizacaoDatas />);

        fireEvent.change(await screen.findByLabelText('Data de liberação da avaliação'), {
            target: { value: '2026-11-01T08:00' },
        });
        fireEvent.click(screen.getByText('Salvar início'));

        expect(await screen.findByText('A liberação precisa ser antes do encerramento.')).toBeInTheDocument();
    });
});
