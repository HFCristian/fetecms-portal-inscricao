import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const esqueciSenhaMock = vi.fn();
vi.mock('../lib/senha.js', () => ({
    esqueciSenha: (...args) => esqueciSenhaMock(...args),
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: () => ({ message: 'erro', fields: {} }),
}));

import EsqueciSenha from './EsqueciSenha.jsx';

describe('EsqueciSenha', () => {
    beforeEach(() => esqueciSenhaMock.mockReset());

    it('envia o e-mail e mostra a mensagem neutra de sucesso', async () => {
        esqueciSenhaMock.mockResolvedValue({
            message: 'Se este e-mail estiver cadastrado, enviamos um link para redefinir a senha.',
        });

        render(<MemoryRouter><EsqueciSenha /></MemoryRouter>);
        fireEvent.change(screen.getByPlaceholderText('seu@email.com'), { target: { value: 'a@b.com' } });
        fireEvent.click(screen.getByRole('button', { name: /ENVIAR LINK/i }));

        await waitFor(() => expect(screen.getByText(/enviamos um link/i)).toBeTruthy());
        expect(esqueciSenhaMock).toHaveBeenCalledWith('a@b.com');
    });
});
