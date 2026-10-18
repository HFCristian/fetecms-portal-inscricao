import axios from 'axios';

/*
| Cliente HTTP da SPA. Auth por cookie (Sanctum SPA, mesma origem):
| - withCredentials envia o cookie de sessão.
| - withXSRFToken faz o axios mandar o header X-XSRF-TOKEN lido do cookie
|   XSRF-TOKEN (necessário para POST/PUT/DELETE protegidos por CSRF).
*/
const http = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Garante o cookie CSRF do Sanctum antes de requisições que mutam estado
 * (login, registro, logout). Deve ser chamado uma vez antes desses POSTs.
 */
export async function ensureCsrf() {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

/**
 * 419 é o token CSRF que não bate com a sessão: o login ou o logout em outra
 * aba trocou o token, ou a sessão sumiu no servidor. O Laravel recusa antes
 * de o controller rodar — nada do pedido foi executado —, então renovar o
 * cookie e repetir **uma vez** é seguro. Se a repetição falhar de novo, o erro
 * segue: sessão perdida de verdade, e o AuthProvider leva para o login.
 */
http.interceptors.response.use(undefined, async (erro) => {
    const config = erro?.config;

    if (erro?.response?.status !== 419 || !config || config._csrfRenovado) {
        throw erro;
    }

    config._csrfRenovado = true;
    await ensureCsrf();

    return http(config);
});

export default http;
