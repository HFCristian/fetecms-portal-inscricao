import http from './http.js';

/**
 * Contas temporárias dos balcões do evento.
 *
 * O `setor` é a aba a que a conta pertence — `credenciamento`, `almoxarifado`
 * ou `avaliacao_presencial` (os voluntários) —, e é ele que separa as listas:
 * cada aba cadastra, renova e encerra **só as suas**, e a conta criada abre
 * somente aquela aba.
 *
 * Toda ação devolve a lista inteira: criar, renovar e desativar mudam a
 * situação de uma linha e podem vencer outras na mesma passada.
 */

// O prefixo da rota nem sempre é o nome do setor: a aba "Avaliação presencial"
// mora em /admin/presencial, e o setor precisa casar com o enum AbaAdmin.
const PREFIXO = { avaliacao_presencial: 'presencial' };

const base = (setor) => `/admin/${PREFIXO[setor] ?? setor}/contas`;

export const getContasTemporarias = (setor = 'credenciamento') =>
    http.get(base(setor)).then((r) => r.data.data);

export const criarContaTemporaria = (payload, setor = 'credenciamento') =>
    http.post(base(setor), payload).then((r) => r.data);

export const renovarContaTemporaria = (id, prazo, setor = 'credenciamento') =>
    http.patch(`${base(setor)}/${id}/renovar`, prazo).then((r) => r.data);

export const desativarContaTemporaria = (id, setor = 'credenciamento') =>
    http.patch(`${base(setor)}/${id}/desativar`).then((r) => r.data);

// --- Presença do turno (lado de quem trabalha) -------------------------------

export const getPresencaContaTemporaria = () =>
    http.get('/contas-temporarias/presenca').then((r) => r.data.data);

export const marcarPresenca = () =>
    http.post('/contas-temporarias/presenca').then((r) => r.data);

/** Aprova ou rejeita a presença de alguém (rejeitar exige motivo). */
export const decidirPresenca = (id, aprovar, motivo = null, setor = 'credenciamento') =>
    http.patch(`${base(setor)}/${id}/presenca`, { aprovar, motivo }).then((r) => r.data);

// --- Lote e remoção (Sprint 155) ---------------------------------------------

/** Baixa o modelo em Excel do cadastro em lote deste setor. */
export async function baixarModeloContas(setor = 'credenciamento') {
    const resp = await http.get(`${base(setor)}/modelo`, { responseType: 'blob' });
    salvarArquivo(resp.data, `modelo-contas-${setor.replace('_', '-')}.xlsx`);
}

/**
 * Envia a planilha preenchida e recebe a prévia linha a linha. Nada é criado:
 * a confirmação é outra chamada, com as linhas que a tela mostrou.
 */
export const previaLoteContas = (arquivo, padroes = {}, setor = 'credenciamento') => {
    const form = new FormData();
    form.append('arquivo', arquivo);
    if (padroes.valido_de) form.append('padroes[valido_de]', padroes.valido_de);
    if (padroes.horas) form.append('padroes[horas]', padroes.horas);
    (padroes.turnos ?? []).forEach((t, i) => {
        form.append(`padroes[turnos][${i}][inicio]`, t.inicio);
        form.append(`padroes[turnos][${i}][fim]`, t.fim);
    });

    return http.post(`${base(setor)}/lote/previa`, form).then((r) => r.data.data);
};

/** Cria as contas da prévia. A resposta traz a planilha de acesso em base64. */
export const criarLoteContas = (linhas, padroes = {}, setor = 'credenciamento') =>
    http.post(`${base(setor)}/lote`, { linhas, padroes }).then((r) => r.data);

export const removerContaTemporaria = (id, setor = 'credenciamento') =>
    http.delete(`${base(setor)}/${id}`).then((r) => r.data);

/** A planilha de acesso chega em base64 na resposta do lote. */
export function baixarBase64(base64, nome, tipo = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
    const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    salvarArquivo(new Blob([bytes], { type: tipo }), nome);
}

function salvarArquivo(blob, nome) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = nome;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
