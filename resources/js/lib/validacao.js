export const MSG_OBRIGATORIO = 'Campo obrigatório.';

// Retorna um objeto de erros { campo: [msg] } para cada campo vazio da lista.
// Considera vazio: undefined, null ou string em branco. Não valida checkboxes.
export function validarObrigatorios(form, campos, msg = MSG_OBRIGATORIO) {
    const errors = {};
    for (const campo of campos) {
        const v = form[campo];
        const vazio = v === undefined || v === null || (typeof v === 'string' && v.trim() === '');
        if (vazio) errors[campo] = [msg];
    }
    return errors;
}
