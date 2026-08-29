import http from './http.js';

// Cadastro à espera da confirmação do e-mail. Enquanto o código de 6 dígitos
// não volta, não existe conta nenhuma: o formulário inteiro está guardado no
// servidor e é identificado por este token.
export const getCadastroPendente = (token) =>
    http.get(`/cadastros/${token}`).then((r) => r.data.data);

export const confirmarCadastro = (token, codigo) =>
    http.post(`/cadastros/${token}/confirmar`, { codigo }).then((r) => r.data.data);

export const reenviarCodigo = (token) =>
    http.post(`/cadastros/${token}/reenviar`).then((r) => r.data);

/** Corrige o e-mail digitado errado — o código vai para o endereço novo. */
export const trocarEmailCadastro = (token, email) =>
    http.patch(`/cadastros/${token}/email`, { email }).then((r) => r.data);
