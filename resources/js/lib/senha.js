import http, { ensureCsrf } from './http.js';

/*
| Recuperação de senha. Rotas públicas, mas o SPA é stateful (Sanctum),
| então garantimos o cookie CSRF antes dos POSTs, como no login/registro.
*/

/** Solicita o e-mail com o link de redefinição. A resposta é sempre neutra. */
export async function esqueciSenha(email) {
    await ensureCsrf();
    const r = await http.post('/auth/esqueci-senha', { email });
    return r.data.data;
}

/** Redefine a senha a partir do token recebido por e-mail. */
export async function redefinirSenha({ token, email, password, password_confirmation }) {
    await ensureCsrf();
    const r = await http.post('/auth/redefinir-senha', {
        token,
        email,
        password,
        password_confirmation,
    });
    return r.data.data;
}
