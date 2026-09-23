import { useCallback, useState } from 'react';

const CHAVE = 'fetec:credenciamento:teste';

/**
 * Modo de teste das abas do dia do evento — credenciamento, almoxarifado e
 * cerimonial (só o admin demo).
 *
 * As três ignoram as mesmas datas e trocam para a mesma lista final de
 * demonstração, então o interruptor é **um só**: ligar o ensaio numa e chegar
 * na outra valendo de verdade seria a armadilha que o modo existe para evitar.
 *
 * Fica no `sessionStorage` para as telas concordarem entre si sem carregar o
 * estado por props — e para não vazar de uma sessão para outra. O backend
 * valida de novo: quem não é demo nunca entra em modo de teste.
 */
export function useModoTeste() {
    const [teste, setEstado] = useState(() => {
        try {
            return sessionStorage.getItem(CHAVE) === '1';
        } catch {
            return false;
        }
    });

    const definir = useCallback((valor) => {
        setEstado(valor);
        try {
            if (valor) sessionStorage.setItem(CHAVE, '1');
            else sessionStorage.removeItem(CHAVE);
        } catch {
            // Navegador sem storage: o modo vale só enquanto a tela estiver aberta.
        }
    }, []);

    return [teste, definir];
}
