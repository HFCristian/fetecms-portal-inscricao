import { createContext, useContext, useEffect, useState, useCallback } from 'react';
import http, { ensureCsrf } from './http.js';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

    // Recupera a sessão atual ao carregar a SPA (cookie Sanctum).
    useEffect(() => {
        http.get('/auth/me')
            .then((r) => setUser(r.data.data))
            .catch(() => setUser(null))
            .finally(() => setLoading(false));
    }, []);

    const login = useCallback(async (email, password, remember = false) => {
        await ensureCsrf();
        const r = await http.post('/auth/login', { email, password, remember });
        setUser(r.data.data);
        return r.data.data;
    }, []);

    const register = useCallback(async (payload) => {
        await ensureCsrf();
        const r = await http.post('/orientadores', payload);
        setUser(r.data.data);
        return r.data.data;
    }, []);

    const registerAvaliador = useCallback(async (payload) => {
        await ensureCsrf();
        const r = await http.post('/avaliadores', payload);
        setUser(r.data.data);
        return r.data.data;
    }, []);

    const logout = useCallback(async () => {
        try {
            await http.post('/auth/logout');
        } finally {
            setUser(null);
        }
    }, []);

    return (
        <AuthContext.Provider value={{ user, setUser, loading, login, register, registerAvaliador, logout }}>
            {children}
        </AuthContext.Provider>
    );
}

/** Rota inicial conforme o papel do usuário. */
export function homeFor(role) {
    if (role === 'avaliador') return '/avaliador';
    if (role === 'admin') return '/admin';
    return '/projetos';
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth precisa estar dentro de <AuthProvider>');
    return ctx;
}

/**
 * Extrai mensagens de erro de validação (422) ou genéricas do axios.
 * Em 429 (excesso de tentativas), `retryAfter` traz os segundos de espera —
 * do corpo da resposta ou do header Retry-After — para a tela mostrar o
 * tempo restante e a contagem regressiva.
 */
export function extractErrors(error) {
    const res = error?.response?.data;
    const status = error?.response?.status;
    const retryAfter =
        status === 429
            ? Number(res?.retry_after ?? error?.response?.headers?.['retry-after']) || 60
            : null;

    if (res?.errors) {
        return { message: res.message, fields: res.errors, status, retryAfter };
    }
    return { message: res?.message || 'Ocorreu um erro inesperado.', fields: {}, status, retryAfter };
}
