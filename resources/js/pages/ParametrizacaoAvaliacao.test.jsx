import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));

const getAvaliacaoConfig = vi.fn();
const definirLimitesAvaliador = vi.fn();
const definirLimitesProjeto = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: (...a) => getAvaliacaoConfig(...a),
    definirLimitesAvaliador: (...a) => definirLimitesAvaliador(...a),
    definirLimitesProjeto: (...a) => definirLimitesProjeto(...a),
}));

import ParametrizacaoAvaliacao from './ParametrizacaoAvaliacao.jsx';

const categorias = [
    { value: 'fetec_jr', label: 'FETEC Jr', min: null, max: null, min_efetivo: 3, max_efetivo: 5 },
    { value: 'fetecms', label: 'FETECMS', min: null, max: null, min_efetivo: 3, max_efetivo: 5 },
    { value: 'fetecms_fundect', label: 'FETECMS FUNDECT', min: null, max: null, min_efetivo: 3, max_efetivo: 5 },
];

const vazio = {
    min_por_avaliador: 3, min_por_projeto: 3,
    max_por_avaliador: null, max_por_projeto: 5,
    categorias, limite_maximo: 50,
};

// A tela ficou só com os limites: as datas do período (e as do período de
// ajustes) mudaram-se para Parametrização → Datas e períodos.
describe('ParametrizacaoAvaliacao', () => {
    beforeEach(() => {
        getAvaliacaoConfig.mockReset().mockResolvedValue(vazio);
        definirLimitesAvaliador.mockReset();
        definirLimitesProjeto.mockReset();
    });

    it('não mostra mais os campos de data — eles apontam para a tela nova', async () => {
        render(<ParametrizacaoAvaliacao />);

        expect(await screen.findByLabelText('Mínimo — Por avaliador')).toBeInTheDocument();
        expect(screen.queryByLabelText('Data de liberação da avaliação')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Data de início do período de ajustes')).not.toBeInTheDocument();
        expect(screen.getByText('Datas e períodos')).toBeInTheDocument();
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
