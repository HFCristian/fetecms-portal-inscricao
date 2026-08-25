import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getAvaliacaoConfig = vi.fn();
const definirLiberacaoAvaliacao = vi.fn();
const definirEncerramentoAvaliacao = vi.fn();
const definirMinimoPorAvaliador = vi.fn();
const definirMinimoPorProjeto = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: (...a) => getAvaliacaoConfig(...a),
    definirLiberacaoAvaliacao: (...a) => definirLiberacaoAvaliacao(...a),
    definirEncerramentoAvaliacao: (...a) => definirEncerramentoAvaliacao(...a),
    definirMinimoPorAvaliador: (...a) => definirMinimoPorAvaliador(...a),
    definirMinimoPorProjeto: (...a) => definirMinimoPorProjeto(...a),
}));

import ParametrizacaoAvaliacao from './ParametrizacaoAvaliacao.jsx';

const vazio = {
    liberada: false, encerrada: false,
    liberada_em_input: null, liberada_em_label: null,
    encerrada_em_input: null, encerrada_em_label: null,
    min_por_avaliador: 3, min_por_projeto: 3,
};
const comFim = { ...vazio, encerrada_em_input: '2026-10-10T18:00', encerrada_em_label: '10/10/2026 18:00' };
const encerrada = { ...comFim, liberada: true, encerrada: true, liberada_em_label: '01/10/2026 08:00' };

describe('ParametrizacaoAvaliacao', () => {
    beforeEach(() => {
        getAvaliacaoConfig.mockReset().mockResolvedValue(vazio);
        definirLiberacaoAvaliacao.mockReset();
        definirEncerramentoAvaliacao.mockReset();
        definirMinimoPorAvaliador.mockReset();
        definirMinimoPorProjeto.mockReset();
    });

    it('mostra as duas datas do período sem nada definido', async () => {
        render(<ParametrizacaoAvaliacao />);

        expect(await screen.findByText('Sem data definida')).toBeInTheDocument();
        expect(screen.getByText('Sem encerramento')).toBeInTheDocument();
        expect(screen.getByText('Salvar fim').closest('button')).toBeDisabled();
    });

    it('salva a data de encerramento', async () => {
        definirEncerramentoAvaliacao.mockResolvedValue({
            data: comFim, meta: { message: 'Encerramento da avaliação salvo.' },
        });
        render(<ParametrizacaoAvaliacao />);

        fireEvent.change(await screen.findByLabelText('Data de encerramento da avaliação'), {
            target: { value: '2026-10-10T18:00' },
        });
        fireEvent.click(screen.getByText('Salvar fim'));

        await waitFor(() => expect(definirEncerramentoAvaliacao).toHaveBeenCalledWith('2026-10-10T18:00'));
        expect(await screen.findByText('Encerramento da avaliação salvo.')).toBeInTheDocument();
        expect(screen.getByText('Encerra em 10/10/2026 18:00')).toBeInTheDocument();
    });

    it('avisa quando o período já se encerrou', async () => {
        getAvaliacaoConfig.mockResolvedValue(encerrada);
        render(<ParametrizacaoAvaliacao />);

        expect(await screen.findByText('Avaliação encerrada')).toBeInTheDocument();
        expect(screen.getByText('Avaliação liberada')).toBeInTheDocument();
    });

    it('mostra o erro de ordem vindo do backend', async () => {
        getAvaliacaoConfig.mockResolvedValue(comFim);
        definirLiberacaoAvaliacao.mockRejectedValue({
            response: { status: 422, data: { message: 'A liberação precisa ser antes do encerramento.' } },
        });
        render(<ParametrizacaoAvaliacao />);

        fireEvent.change(await screen.findByLabelText('Data de liberação da avaliação'), {
            target: { value: '2026-11-01T08:00' },
        });
        fireEvent.click(screen.getByText('Salvar início'));

        expect(await screen.findByText('A liberação precisa ser antes do encerramento.')).toBeInTheDocument();
    });

    it('mostra os mínimos configurados', async () => {
        render(<ParametrizacaoAvaliacao />);

        expect(await screen.findByLabelText('Mínimo de avaliações por avaliador')).toHaveValue(3);
        expect(screen.getByLabelText('Mínimo de avaliações por projeto')).toHaveValue(3);
    });

    it('salva o mínimo por avaliador', async () => {
        definirMinimoPorAvaliador.mockResolvedValue({
            data: { ...vazio, min_por_avaliador: 5 }, meta: { message: 'Mínimos atualizados.' },
        });
        render(<ParametrizacaoAvaliacao />);

        fireEvent.change(await screen.findByLabelText('Mínimo de avaliações por avaliador'), {
            target: { value: '5' },
        });
        fireEvent.click(screen.getByText('Salvar mínimo por avaliador'));

        await waitFor(() => expect(definirMinimoPorAvaliador).toHaveBeenCalledWith(5));
        expect(await screen.findByText('Mínimos atualizados.')).toBeInTheDocument();
    });

    it('não deixa salvar um mínimo fora da faixa', async () => {
        render(<ParametrizacaoAvaliacao />);

        fireEvent.change(await screen.findByLabelText('Mínimo de avaliações por projeto'), {
            target: { value: '0' },
        });

        expect(screen.getByText('Salvar mínimo por projeto').closest('button')).toBeDisabled();
        expect(definirMinimoPorProjeto).not.toHaveBeenCalled();
    });
});
