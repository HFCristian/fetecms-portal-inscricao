import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const marcarPresenca = vi.fn();
vi.mock('../lib/contasTemporarias.js', () => ({
    marcarPresenca: (...a) => marcarPresenca(...a),
}));

import CartaoPresenca from './CartaoPresenca.jsx';

const BASE = {
    setor: 'credenciamento', setor_label: 'Credenciamento',
    status: null, motivo: null, precisa_marcar: true, aprovada: false,
    agendada: false, vencida: false,
    valido_de_label: null, expira_em_label: '20/10/2026 13:00', turnos: [],
};

describe('CartaoPresenca', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        marcarPresenca.mockResolvedValue({ data: { ...BASE, status: 'pendente', precisa_marcar: false } });
    });

    it('oferece marcar presença no turno', async () => {
        const onAtualizar = vi.fn();
        render(<CartaoPresenca presenca={BASE} onAtualizar={onAtualizar} />);

        expect(screen.getByText('Marque a sua presença para começar')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Marcar presença/i }));

        await waitFor(() => expect(marcarPresenca).toHaveBeenCalled());
        await waitFor(() => expect(onAtualizar).toHaveBeenCalled());
    });

    it('marcada, explica que falta a organização confirmar', () => {
        render(<CartaoPresenca presenca={{ ...BASE, status: 'pendente', precisa_marcar: false }} />);

        expect(screen.getByText(/aguardando a organização/i)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Marcar presença/i })).not.toBeInTheDocument();
    });

    it('rejeitada, mostra o motivo', () => {
        render(<CartaoPresenca presenca={{
            ...BASE, status: 'rejeitada', precisa_marcar: false, motivo: 'Turno já coberto.',
        }} />);

        expect(screen.getByText('A sua presença foi recusada')).toBeInTheDocument();
        expect(screen.getByText(/Turno já coberto\./)).toBeInTheDocument();
    });

    it('agendada, não oferece marcar antes da hora', () => {
        render(<CartaoPresenca presenca={{
            ...BASE, precisa_marcar: false, agendada: true, valido_de_label: '20/10/2026 08:00',
        }} />);

        expect(screen.getByText('O seu acesso ainda não começou')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Marcar presença/i })).not.toBeInTheDocument();
    });

    it('presença aprovada não desenha nada — o menu já abriu', () => {
        const { container } = render(<CartaoPresenca presenca={{ ...BASE, aprovada: true, status: 'aprovada' }} />);

        expect(container).toBeEmptyDOMElement();
    });
});
