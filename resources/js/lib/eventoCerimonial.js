import { useCallback, useState } from 'react';

/** O "Todos" do painel: a soma do público de todos os eventos. */
export const TODOS = 'todos';

const CHAVES = {
    porta: 'fetec:cerimonial:evento:porta',
    painel: 'fetec:cerimonial:evento:painel',
};

function ler(chave) {
    try {
        return localStorage.getItem(chave);
    } catch {
        return null;
    }
}

/**
 * O evento de cerimônia escolhido **neste aparelho**.
 *
 * Fica no `localStorage` porque o tablet da porta da FETECMS passa a tarde
 * inteira na FETECMS: recarregar a página não pode devolvê-lo à escolha. São
 * duas memórias, porque são dois usos:
 *
 * - `porta` (check-in e ficha): precisa de **um** evento. Com um só cadastrado
 *   ele já vem escolhido; com mais de um, ninguém escolhe pelo atendente —
 *   registrar a entrada na cerimônia errada é o erro que a escolha evita;
 * - `painel` (Visão Geral e premiados): aceita **Todos**, que é o padrão.
 *
 * Devolve `[valor, definir]`: o id do evento, `TODOS`, ou `null` (porta sem
 * escolha). O valor guardado só vale se o evento ainda existir na lista.
 */
export function useEventoCerimonial(eventos, lugar = 'porta') {
    const chave = CHAVES[lugar] ?? CHAVES.porta;
    const [guardado, setGuardado] = useState(() => ler(chave));

    const definir = useCallback((valor) => {
        const texto = valor === null || valor === undefined ? null : String(valor);
        setGuardado(texto);
        try {
            if (texto === null) localStorage.removeItem(chave);
            else localStorage.setItem(chave, texto);
        } catch {
            // Sem storage: a escolha vale só enquanto a tela estiver aberta.
        }
    }, [chave]);

    const lista = eventos ?? [];
    const existe = lista.find((e) => String(e.id) === guardado);
    let valor = null;

    if (existe) valor = existe.id;
    else if (lugar === 'painel') valor = TODOS;
    else if (lista.length === 1) valor = lista[0].id;

    return [valor, definir];
}

/** O evento guardado da porta, sem a lista — para a ficha, que abre por link. */
export function eventoDaPorta() {
    const valor = Number(ler(CHAVES.porta));

    return Number.isInteger(valor) && valor > 0 ? valor : null;
}
