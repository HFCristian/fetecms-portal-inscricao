import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const navigateMock = vi.fn();
vi.mock('react-router-dom', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, useNavigate: () => navigateMock };
});

const loginMock = vi.fn();
const extractErrorsMock = vi.fn(() => ({ message: 'erro', fields: {} }));
vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({ login: loginMock }),
    extractErrors: (e) => extractErrorsMock(e),
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
        extractErrorsMock.mockReset();
        extractErrorsMock.mockReturnValue({ message: 'erro', fields: {} });
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

    describe('bloqueio por excesso de tentativas (429)', () => {
        function bloquear(segundos = 65) {
            loginMock.mockRejectedValue(new Error('429'));
            extractErrorsMock.mockReturnValue({
                message: 'Tentativas de login em excesso. Tente novamente em 1 minuto e 5 segundos.',
                fields: {},
                status: 429,
                retryAfter: segundos,
            });
        }

        it('avisa o motivo, o tempo de espera e mostra a contagem regressiva', async () => {
            bloquear(65);
            render(<MemoryRouter><Login /></MemoryRouter>);
            preencherEEnviar();

            await screen.findByText('Acesso bloqueado temporariamente');
            expect(screen.getByText(/Tente novamente em 1 minuto e 5 segundos/)).toBeInTheDocument();
            expect(screen.getByRole('timer')).toHaveTextContent('01:05');
        });

        it('desabilita o envio enquanto o bloqueio durar', async () => {
            bloquear(65);
            render(<MemoryRouter><Login /></MemoryRouter>);
            preencherEEnviar();

            const botao = await screen.findByRole('button', { name: /AGUARDE 01:05/i });
            expect(botao).toBeDisabled();
        });

        it('conta até 00:00 e libera o botão ao terminar a espera', async () => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
            try {
                bloquear(3);
                render(<MemoryRouter><Login /></MemoryRouter>);
                preencherEEnviar();

                await vi.waitFor(() => expect(screen.getByRole('timer')).toHaveTextContent('00:03'));

                await act(async () => { await vi.advanceTimersByTimeAsync(3200); });

                expect(screen.queryByRole('timer')).not.toBeInTheDocument();
                expect(screen.getByText(/A espera terminou/)).toBeInTheDocument();
                expect(screen.getByRole('button', { name: /ENTRAR/i })).not.toBeDisabled();
            } finally {
                vi.useRealTimers();
            }
        });
    });
});
