import { createContext, useContext, useEffect, useRef, useState, useCallback } from 'react';
import http, { ensureCsrf } from './http.js';
import { confirmarCadastro as confirmarCadastroApi } from './cadastro.js';

const AuthContext = createContext(null);

/**
 * Respostas que dizem que a sessão de quem estava logado acabou: 401 (não
 * autenticado) e o 419 que sobrou depois de renovar o CSRF (http.js). As
 * rotas de `/auth/` ficam de fora — o login errado e o logout respondem por si.
 */
export function sessaoPerdida(erro) {
    const status = erro?.response?.status;
    const url = erro?.config?.url ?? '';

    return (status === 401 || status === 419) && !url.startsWith('/auth/');
}

export function AuthProvider({ children }) {
    const [user, setUserState] = useState(null);
    const [loading, setLoading] = useState(true);
    // A sessão de quem estava logado acabou (expirou, ou foi derrubada no
    // servidor): o login explica, em vez de a tela seguir aberta com cada
    // ação falhando — que é o que o usuário chama de "travou".
    const [sessaoExpirada, setSessaoExpirada] = useState(false);
    const logado = useRef(false);

    const setUser = useCallback((novo) => {
        logado.current = Boolean(novo);
        if (novo) setSessaoExpirada(false);
        setUserState(novo);
    }, []);

    useEffect(() => {
        const id = http.interceptors.response.use(undefined, (erro) => {
            if (logado.current && sessaoPerdida(erro)) {
                setUser(null);
                setSessaoExpirada(true);
            }
            return Promise.reject(erro);
        });

        return () => http.interceptors.response.eject(id);
    }, [setUser]);

    // Recupera a sessão atual ao carregar a SPA (cookie Sanctum).
    useEffect(() => {
        http.get('/auth/me')
            .then((r) => setUser(r.data.data))
            .catch(() => setUser(null))
            .finally(() => setLoading(false));
    }, [setUser]);

    const login = useCallback(async (email, password, remember = false) => {
        await ensureCsrf();
        const r = await http.post('/auth/login', { email, password, remember });
        setUser(r.data.data);
        return r.data.data;
    }, [setUser]);

    // Cadastro NÃO cria a conta: abre um cadastro pendente e manda o código de
    // 6 dígitos por e-mail. Quem cria o usuário (e loga) é o confirmar().
    const register = useCallback(async (payload) => {
        await ensureCsrf();
        const r = await http.post('/orientadores', payload);
        return r.data.data;
    }, []);

    const registerAvaliador = useCallback(async (payload) => {
        await ensureCsrf();
        const r = await http.post('/avaliadores', payload);
        return r.data.data;
    }, []);

    /** Código correto: a conta nasce agora e a sessão já vem logada. */
    const confirmar = useCallback(async (token, codigo) => {
        const novo = await confirmarCadastroApi(token, codigo);
        setUser(novo);
        return novo;
    }, [setUser]);

    const logout = useCallback(async () => {
        try {
            await http.post('/auth/logout');
        } finally {
            setUser(null);
        }
    }, [setUser]);

    return (
        <AuthContext.Provider value={{ user, setUser, loading, login, register, registerAvaliador, confirmar, logout, sessaoExpirada }}>
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
