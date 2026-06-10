// Idade mínima (em anos completos) exigida para orientador e coorientador.
export const MIN_IDADE = 21;

// Calcula a idade em anos completos a partir de uma data ISO (yyyy-mm-dd).
// Retorna null se a data for inválida/incompleta.
export function idadeEmAnos(iso, hoje = new Date()) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '');
    if (!m) return null;
    const ano = Number(m[1]);
    const mes = Number(m[2]);
    const dia = Number(m[3]);
    let idade = hoje.getFullYear() - ano;
    const mesAtual = hoje.getMonth() + 1;
    const aniversarioPassou = mesAtual > mes || (mesAtual === mes && hoje.getDate() >= dia);
    if (!aniversarioPassou) idade -= 1;
    return idade;
}
