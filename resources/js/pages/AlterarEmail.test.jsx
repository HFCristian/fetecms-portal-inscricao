import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));

const put = vi.fn();
vi.mock('../lib/http.js', () => ({ default: { put: (...args) => put(...args) } }));

const setUser = vi.fn();
vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({ user: { name: 'Ana', email: 'antigo@escola.test', role: 'avaliador' }, setUser }),
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? 'Erro',
        fields: e?.response?.data?.errors ?? {},
    }),
}));

import AlterarEmail from './AlterarEmail.jsx';

describe('AlterarEmail', () => {
    beforeEach(() => { put.mockReset(); setUser.mockReset(); });

    it('mostra o e-mail atual em campo somente leitura', () => {
        render(<AlterarEmail />);
        expect(screen.getByDisplayValue('antigo@escola.test')).toBeDisabled();
    });

    it('envia o novo e-mail e confirma a troca', async () => {
        put.mockResolvedValue({ data: { data: { email: 'novo@escola.test' } } });
        render(<AlterarEmail />);

        fireEvent.change(screen.getByLabelText(/Novo e-mail/), { target: { value: 'novo@escola.test' } });
        fireEvent.click(screen.getByRole('button', { name: /Salvar novo e-mail/ }));

        await waitFor(() => expect(put).toHaveBeenCalledWith('/auth/email', { email: 'novo@escola.test' }));
        expect(await screen.findByText(/agora é novo@escola.test/)).toBeInTheDocument();
        expect(setUser).toHaveBeenCalled();
    });

    it('mostra o erro de e-mail já em uso', async () => {
        put.mockRejectedValue({
            response: { status: 422, data: { message: 'Dados inválidos.', errors: { email: ['Este e-mail já está em uso por outra conta.'] } } },
        });
        render(<AlterarEmail />);

        fireEvent.change(screen.getByLabelText(/Novo e-mail/), { target: { value: 'ocupado@escola.test' } });
        fireEvent.click(screen.getByRole('button', { name: /Salvar novo e-mail/ }));

        expect(await screen.findByText('Este e-mail já está em uso por outra conta.')).toBeInTheDocument();
    });
});
