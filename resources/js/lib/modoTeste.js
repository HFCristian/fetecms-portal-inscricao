import { useCallback, useState } from 'react';

const CHAVE = 'fetec:credenciamento:teste';

/**
 * Modo de teste do credenciamento (só o admin demo).
 *
 * Fica no `sessionStorage` para as telas da aba concordarem entre si sem
 * carregar o estado por props — e para não vazar de uma sessão para outra.
 * O backend valida de novo: quem não é demo nunca entra em modo de teste.
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
