import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));

const DADOS = {
    pedidos: [{
        id: 4, tipo: 'acompanhante', tipo_label: 'Acompanhante', status: 'pendente', status_label: 'Aguardando a organização',
        resumo: 'Acompanhante: Maria (Mãe) — de Ana', projeto: 'Biofiltro', orientador: 'Marta', acompanhante_documento: 'RG 1',
    }],
    totais: { pendente: 1, aprovado: 0, recusado: 0 },
    tipos: [{ value: 'acompanhante', label: 'Acompanhante' }],
};

const getSuportesAdmin = vi.fn();
const decidirSuporte = vi.fn();
vi.mock('../lib/credenciamento.js', () => ({
    getSuportesAdmin: (...a) => getSuportesAdmin(...a),
    decidirSuporte: (...a) => decidirSuporte(...a),
    criarSuporteAdmin: vi.fn(),
    excluirSuporteAdmin: vi.fn(),
    getFinalistas: () => Promise.resolve({ data: [] }),
    getFichaCredenciamento: vi.fn(),
}));

import CredenciamentoSuporte from './CredenciamentoSuporte.jsx';

describe('CredenciamentoSuporte', () => {
    beforeEach(() => {
        getSuportesAdmin.mockReset().mockResolvedValue(DADOS);
        decidirSuporte.mockReset().mockResolvedValue({ data: { ...DADOS, totais: { pendente: 0, aprovado: 1, recusado: 0 } }, meta: { message: 'Pedido aprovado.' } });
    });

    it('aprova um pedido', async () => {
        render(<CredenciamentoSuporte />);

        fireEvent.click(await screen.findByRole('button', { name: /Aprovar/ }));
        await waitFor(() => expect(decidirSuporte).toHaveBeenCalledWith(4, true));
        expect(await screen.findByText('Pedido aprovado.')).toBeInTheDocument();
    });

    it('recusar exige motivo', async () => {
        render(<CredenciamentoSuporte />);

        fireEvent.click(await screen.findByRole('button', { name: 'Recusar' }));
        const confirmar = screen.getAllByRole('button', { name: 'Recusar' }).at(-1);
        expect(confirmar).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Motivo da recusa'), { target: { value: 'Sem vaga no ônibus.' } });
        fireEvent.click(confirmar);

        await waitFor(() => expect(decidirSuporte).toHaveBeenCalledWith(4, false, 'Sem vaga no ônibus.'));
    });
});
