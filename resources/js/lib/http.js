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

export default http;
