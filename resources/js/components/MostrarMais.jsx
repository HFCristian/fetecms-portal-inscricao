/** De quantos em quantos as listas de designação pedem mais itens ao servidor. */
export const PASSO_LISTA = 30;

/**
 * O rodapé de uma lista que o servidor corta: quantos estão na tela, de
 * quantos casaram com a busca, e o botão para trazer mais.
 *
 * Existe porque o corte era silencioso: com o filtro "Turno B", só os 30
 * primeiros finalistas do alfabeto apareciam, e o resto parecia não existir.
 */
export default function MostrarMais({ exibidos, total, onMais, carregando = false }) {
    if (total === undefined || total === null || total <= exibidos) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-2 mt-2 text-xs text-on-surface-variant">
            <span>Mostrando {exibidos} de {total}. Refine a busca ou mostre mais.</span>
            <button
                type="button"
                className="text-primary font-semibold hover:underline shrink-0 disabled:opacity-50"
                disabled={carregando}
                onClick={onMais}
            >
                Mostrar mais {Math.min(PASSO_LISTA, total - exibidos)}
            </button>
        </div>
    );
}
