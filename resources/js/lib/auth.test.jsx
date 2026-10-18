import { render, screen, waitFor, act } from '@testing-library/react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import http from './http.js';
import { AuthProvider, useAuth, sessaoPerdida } from './auth.jsx';

vi.mock('./cadastro.js', () => ({ confirmarCadastro: vi.fn() }));

/** Responde por URL: `rotas['/auth/me'] = 200 | 401 | ...`. */
function servidor(rotas) {
    http.defaults.adapter = (config) => {
        const status = rotas[config.url] ?? 200;
        const data = config.url === '/auth/me' && status === 200 ? { data: { id: 1, name: 'Ana', role: 'avaliador' } } : {};
        const response = { data, status, statusText: '', headers: {}, config };

        return status < 400
            ? Promise.resolve(response)
            : Promise.reject(Object.assign(new Error(String(status)), { config, response }));
    };
}

function Estado() {
    const { user, loading, sessaoExpirada } = useAuth();
    if (loading) return <p>carregando</p>;
    return <p>{user ? `logado:${user.name}` : 'deslogado'} · {sessaoExpirada ? 'expirou' : 'ok'}</p>;
}

describe('AuthProvider: sessão perdida', () => {
    const adaptadorOriginal = http.defaults.adapter;
    afterEach(() => { http.defaults.adapter = adaptadorOriginal; });

    it('um 401 de quem estava logado desloga e marca a sessão como expirada', async () => {
        servidor({ '/auth/me': 200, '/avaliador/presencial/avaliacoes': 401 });
        render(<AuthProvider><Estado /></AuthProvider>);
        expect(await screen.findByText('logado:Ana · ok')).toBeInTheDocument();

        await act(async () => {
            await http.get('/avaliador/presencial/avaliacoes').catch(() => {});
        });

        await waitFor(() => expect(screen.getByText('deslogado · expirou')).toBeInTheDocument());
    });

    it('o 401 do visitante ainda sem login não vira "sessão expirada"', async () => {
        servidor({ '/auth/me': 401, '/inscricoes/publico': 401 });
        render(<AuthProvider><Estado /></AuthProvider>);
        expect(await screen.findByText('deslogado · ok')).toBeInTheDocument();

        await act(async () => {
            await http.get('/inscricoes/publico').catch(() => {});
        });

        expect(screen.getByText('deslogado · ok')).toBeInTheDocument();
    });

    it('só 401 e 419 fora de /auth/ contam como sessão perdida', () => {
        const erro = (status, url) => ({ response: { status }, config: { url } });

        expect(sessaoPerdida(erro(401, '/projetos'))).toBe(true);
        expect(sessaoPerdida(erro(419, '/projetos'))).toBe(true);
        expect(sessaoPerdida(erro(403, '/projetos'))).toBe(false);
        expect(sessaoPerdida(erro(401, '/auth/login'))).toBe(false);
        expect(sessaoPerdida({ message: 'Network Error' })).toBe(false);
    });
});
