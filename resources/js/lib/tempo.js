// Tempo relativo em pt-BR ("agora mesmo", "há 5 min", "há 2 dias").
// Usado para mostrar há quanto tempo uma mensagem do chat foi enviada.
export function tempoRelativo(iso) {
    if (!iso) return '';
    const ms = new Date(iso).getTime();
    if (Number.isNaN(ms)) return '';

    const segundos = Math.max(0, Math.round((Date.now() - ms) / 1000));
    if (segundos < 45) return 'agora mesmo';

    const minutos = Math.round(segundos / 60);
    if (minutos < 60) return `há ${minutos} min`;

    const horas = Math.round(minutos / 60);
    if (horas < 24) return `há ${horas} h`;

    const dias = Math.round(horas / 24);
    if (dias < 30) return `há ${dias} ${dias === 1 ? 'dia' : 'dias'}`;

    const meses = Math.round(dias / 30);
    if (meses < 12) return `há ${meses} ${meses === 1 ? 'mês' : 'meses'}`;

    const anos = Math.round(meses / 12);
    return `há ${anos} ${anos === 1 ? 'ano' : 'anos'}`;
}
