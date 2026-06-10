import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const navigateMock = vi.fn();
vi.mock('react-router-dom', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, useNavigate: () => navigateMock };
});

const loginMock = vi.fn();
vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({ login: loginMock }),
    extractErrors: () => ({ message: 'erro', fields: {} }),
    homeFor: (role) => (role === 'avaliador' ? '/avaliador' : role === 'admin' ? '/admin' : '/projetos'),
}));

import Login from './Login.jsx';

function preencherEEnviar() {
    fireEvent.change(screen.getByPlaceholderText('seu@email.com'), { target: { value: 'a@b.com' } });
    fireEvent.change(screen.getByPlaceholderText('••••••••'), { target: { value: 'segredo' } });
    fireEvent.click(screen.getByRole('button', { name: /ENTRAR/i }));
}

describe('Login', () => {
    beforeEach(() => {
        navigateMock.mockClear();
        loginMock.mockReset();
    });

    it('redireciona o avaliador para /avaliador (home do papel, não /projetos)', async () => {
        loginMock.mockResolvedValue({ role: 'avaliador' });
        render(<MemoryRouter><Login /></MemoryRouter>);
        preencherEEnviar();
        await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/avaliador', { replace: true }));
    });

    it('redireciona o orientador para /projetos', async () => {
        loginMock.mockResolvedValue({ role: 'orientador' });
        render(<MemoryRouter><Login /></MemoryRouter>);
        preencherEEnviar();
        await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/projetos', { replace: true }));
    });
});
