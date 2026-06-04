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

    const logout = useCallback(async () => {
        try {
            await http.post('/auth/logout');
        } finally {
            setUser(null);
        }
    }, []);

    return (
        <AuthContext.Provider value={{ user, setUser, loading, login, register, logout }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth precisa estar dentro de <AuthProvider>');
    return ctx;
}

/** Extrai mensagens de erro de validação (422) ou genéricas do axios. */
export function extractErrors(error) {
    const res = error?.response?.data;
    if (res?.errors) {
        return { message: res.message, fields: res.errors };
    }
    return { message: res?.message || 'Ocorreu um erro inesperado.', fields: {} };
}
