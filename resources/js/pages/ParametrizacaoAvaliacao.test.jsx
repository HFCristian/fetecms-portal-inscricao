import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getAvaliacaoConfig = vi.fn();
const definirLiberacaoAvaliacao = vi.fn();
const definirEncerramentoAvaliacao = vi.fn();
const definirLimitesAvaliador = vi.fn();
const definirLimitesProjeto = vi.fn();
const definirInicioAjustes = vi.fn();
const definirFimAjustes = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: (...a) => getAvaliacaoConfig(...a),
    definirLiberacaoAvaliacao: (...a) => definirLiberacaoAvaliacao(...a),
    definirEncerramentoAvaliacao: (...a) => definirEncerramentoAvaliacao(...a),
    definirLimitesAvaliador: (...a) => definirLimitesAvaliador(...a),
    definirLimitesProjeto: (...a) => definirLimitesProjeto(...a),
    definirInicioAjustes: (...a) => definirInicioAjustes(...a),
    definirFimAjustes: (...a) => definirFimAjustes(...a),
}));

import ParametrizacaoAvaliacao from './ParametrizacaoAvaliacao.jsx';

const categorias = [
    { value: 'fetec_jr', label: 'FETEC Jr', min: null, max: null, min_efetivo: 3, max_efetivo: 5 },
    { value: 'fetecms', label: 'FETECMS', min: null, max: null, min_efetivo: 3, max_efetivo: 5 },
    { value: 'fetecms_fundect', label: 'FETECMS FUNDECT', min: null, max: null, min_efetivo: 3, max_efetivo: 5 },
];

const vazio = {
    liberada: false, encerrada: false,
    liberada_em_input: null, liberada_em_label: null,
    encerrada_em_input: null, encerrada_em_label: null,
    min_por_avaliador: 3, min_por_projeto: 3,
    max_por_avaliador: null, max_por_projeto: 5,
    categorias, limite_maximo: 50,
};
const comFim = { ...vazio, encerrada_em_input: '2026-10-10T18:00', encerrada_em_label: '10/10/2026 18:00' };
const encerrada = { ...comFim, liberada: true, encerrada: true, liberada_em_label: '01/10/2026 08:00' };

describe('ParametrizacaoAvaliacao', () => {
    beforeEach(() => {
        getAvaliacaoConfig.mockReset().mockResolvedValue(vazio);
        definirLiberacaoAvaliacao.mockReset();
        definirEncerramentoAvaliacao.mockReset();
        definirLimitesAvaliador.mockReset();
        definirLimitesProjeto.mockReset();
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

    it('mostra os limites configurados, com o teto por avaliador em branco', async () => {
        render(<ParametrizacaoAvaliacao />);

        expect(await screen.findByLabelText('Mínimo — Por avaliador')).toHaveValue(3);
        expect(screen.getByLabelText('Máximo — Por avaliador')).toHaveValue(null);
        expect(screen.getByLabelText('Mínimo — Geral')).toHaveValue(3);
        expect(screen.getByLabelText('Máximo — Geral')).toHaveValue(5);
        // Categoria sem número próprio mostra o geral como placeholder.
        expect(screen.getByLabelText('Mínimo — FETEC Jr')).toHaveAttribute('placeholder', '3');
    });

    it('salva o par mínimo/máximo por avaliador', async () => {
        definirLimitesAvaliador.mockResolvedValue({
            data: { ...vazio, min_por_avaliador: 5, max_por_avaliador: 8 },
            meta: { message: 'Limites atualizados.' },
        });
        render(<ParametrizacaoAvaliacao />);

        fireEvent.change(await screen.findByLabelText('Mínimo — Por avaliador'), { target: { value: '5' } });
        fireEvent.change(screen.getByLabelText('Máximo — Por avaliador'), { target: { value: '8' } });
        fireEvent.click(screen.getAllByText('Salvar')[0]);

        await waitFor(() => expect(definirLimitesAvaliador).toHaveBeenCalledWith(5, 8));
        expect(await screen.findByText('Limites atualizados.')).toBeInTheDocument();
    });

    it('salva o mínimo por projeto de uma categoria só', async () => {
        definirLimitesProjeto.mockResolvedValue({ data: vazio, meta: { message: 'Limites atualizados.' } });
        render(<ParametrizacaoAvaliacao />);

        fireEvent.change(await screen.findByLabelText('Mínimo — FETEC Jr'), { target: { value: '2' } });
        fireEvent.click(screen.getAllByText('Salvar')[1]);

        await waitFor(() => expect(definirLimitesProjeto).toHaveBeenCalledWith(3, 5, {
            fetec_jr: { min: 2, max: null },
            fetecms: { min: null, max: null },
            fetecms_fundect: { min: null, max: null },
        }));
    });

    it('não deixa salvar com o máximo abaixo do mínimo', async () => {
        render(<ParametrizacaoAvaliacao />);

        fireEvent.change(await screen.findByLabelText('Máximo — Geral'), { target: { value: '1' } });

        expect(screen.getAllByText('Salvar')[1].closest('button')).toBeDisabled();
        expect(definirLimitesProjeto).not.toHaveBeenCalled();
    });
});
