import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.response?.data?.message ?? '', fields: {} }),
}));

const getCheckinInterpretes = vi.fn();
const getAcompanhamentoInterpretes = vi.fn();
const registrarChegadaInterprete = vi.fn();
const desfazerChegadaInterprete = vi.fn();
vi.mock('../lib/interpretes.js', async (importOriginal) => ({
    ...(await importOriginal()),
    getCheckinInterpretes: (...a) => getCheckinInterpretes(...a),
    getAcompanhamentoInterpretes: (...a) => getAcompanhamentoInterpretes(...a),
    registrarChegadaInterprete: (...a) => registrarChegadaInterprete(...a),
    desfazerChegadaInterprete: (...a) => desfazerChegadaInterprete(...a),
}));

import PresencialInterpretesAcompanhamento from './PresencialInterpretesAcompanhamento.jsx';

const CONFIG = {
    ocorrencias: [{ chave: '2026-10-20|A', rotulo: '20/10 · Turno A (matutino)', situacao: 'em_andamento' }],
    em_foco: '2026-10-20|A',
};

const ACOMPANHAMENTO = {
    totais: { esperados: 2, presentes: 1, faltando: 1, extras: 0 },
    interpretes: [
        { id: 8, nome: 'Caio Lima', tipos_labels: ['Espanhol'], esperado: true, estandes: [5], projetos: ['FET.AGR-001 - Bioplástico'], presente: false },
        { id: 7, nome: 'Bia Souza', tipos_labels: ['Libras'], esperado: true, estandes: [5], projetos: [], presente: true, checkin_em: '08:02', registrado_por: 'Pedro' },
    ],
};

describe('PresencialInterpretesAcompanhamento', () => {
    beforeEach(() => {
        getCheckinInterpretes.mockReset().mockResolvedValue(CONFIG);
        getAcompanhamentoInterpretes.mockReset().mockResolvedValue(ACOMPANHAMENTO);
        registrarChegadaInterprete.mockReset().mockResolvedValue({});
        desfazerChegadaInterprete.mockReset().mockResolvedValue({});
    });

    it('mostra os números do turno e começa por quem falta', async () => {
        render(<PresencialInterpretesAcompanhamento />);

        expect(await screen.findByText('Caio Lima')).toBeInTheDocument();
        expect(screen.queryByText('Bia Souza')).not.toBeInTheDocument();
        expect(screen.getByText('Esperados no turno').nextSibling).toHaveTextContent('2');
        expect(getAcompanhamentoInterpretes).toHaveBeenCalledWith({ dia: '2026-10-20', turno: 'A' });

        fireEvent.click(screen.getByRole('button', { name: 'Chegaram' }));
        expect(screen.getByText('Bia Souza')).toBeInTheDocument();
        expect(screen.getByText('Chegou às 08:02 · por Pedro')).toBeInTheDocument();
    });

    it('marca a chegada de quem falta', async () => {
        render(<PresencialInterpretesAcompanhamento />);

        fireEvent.click(await screen.findByRole('button', { name: 'Marcar chegada' }));

        await waitFor(() => expect(registrarChegadaInterprete).toHaveBeenCalledWith(8, { dia: '2026-10-20', turno: 'A' }));
    });

    it('desfazer pede justificativa', async () => {
        render(<PresencialInterpretesAcompanhamento />);
        fireEvent.click(await screen.findByRole('button', { name: 'Chegaram' }));
        fireEvent.click(screen.getByRole('button', { name: 'Desfazer' }));

        const dialogo = screen.getByRole('dialog', { name: 'Desfazer chegada' });
        expect(within(dialogo).getByRole('button', { name: 'Desfazer' })).toBeDisabled();
        fireEvent.change(within(dialogo).getByLabelText('Justificativa'), { target: { value: 'Bipado por engano' } });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Desfazer' }));

        await waitFor(() => expect(desfazerChegadaInterprete).toHaveBeenCalledWith(7, { dia: '2026-10-20', turno: 'A' }, 'Bipado por engano'));
    });
});
