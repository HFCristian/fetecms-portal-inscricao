import { useEffect, useRef } from 'react';

/** Quanto tempo o card de uma leitura fica na tela. */
export const DURACAO_CARD_MS = 2000;

const ESTILOS = {
    novo: {
        caixa: 'bg-secondary-container border-secondary text-on-surface',
        icone: 'check_circle',
        corIcone: 'text-secondary',
        titulo: 'Check-in feito',
        botao: 'bg-secondary',
    },
    repetido: {
        caixa: 'bg-amber-100 border-amber-500 text-on-surface',
        icone: 'history',
        corIcone: 'text-amber-700',
        titulo: 'Já tinha feito check-in',
        botao: 'bg-amber-700',
    },
    erro: {
        caixa: 'bg-error-container border-error text-on-error-container',
        icone: 'error',
        corIcone: 'text-error',
        titulo: 'Crachá não aceito',
        botao: 'bg-error',
    },
};

/**
 * O resultado de uma leitura de crachá na porta da cerimônia.
 *
 * O nome vem grande, para ser lido de longe por quem atende e pela própria
 * pessoa. Entrada nova e entrada repetida **somem sozinhas** em
 * {@link DURACAO_CARD_MS} — a fila não para para alguém fechar o card —, e o
 * tempo que falta é a barra que vai preenchendo o botão **Ok**; clicar fecha
 * antes. O crachá recusado **não some**: alguém precisa olhar para aquela
 * pessoa antes de ela entrar.
 *
 * O card não rouba o foco do campo de leitura: o leitor USB "digita" no campo
 * que está com o foco, e a próxima bipada precisa cair lá.
 */
export default function CardCheckin({ leitura, onFechar }) {
    const estilo = ESTILOS[leitura.tipo] ?? ESTILOS.erro;
    const someSozinho = leitura.tipo !== 'erro';
    const fechar = useRef(onFechar);

    useEffect(() => {
        fechar.current = onFechar;
    });

    useEffect(() => {
        if (!someSozinho) return undefined;

        const timer = setTimeout(() => fechar.current?.(), DURACAO_CARD_MS);
        return () => clearTimeout(timer);
    }, [leitura, someSozinho]);

    return (
        <div className="fixed inset-x-0 top-4 z-50 flex justify-center px-4 pointer-events-none">
            <div
                role={leitura.tipo === 'erro' ? 'alert' : 'status'}
                aria-live="assertive"
                className={`pointer-events-auto w-full max-w-md rounded-xl border-2 shadow-xl p-5 ${estilo.caixa}`}
            >
                <div className="flex items-start gap-3">
                    <span className={`material-symbols-outlined text-[40px] shrink-0 ${estilo.corIcone}`} aria-hidden="true">
                        {estilo.icone}
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-semibold uppercase tracking-wide">{estilo.titulo}</p>
                        {leitura.nome ? (
                            <>
                                <p className="font-headline text-2xl font-bold leading-tight break-words">{leitura.nome}</p>
                                <p className="text-sm mt-1">
                                    {leitura.papel_label}
                                    {leitura.projeto_titulo ? ` · ${leitura.projeto_titulo}` : ''}
                                </p>
                                {leitura.tipo === 'repetido' && leitura.checkin_em && (
                                    <p className="text-sm font-semibold mt-1">Entrou às {leitura.checkin_em}.</p>
                                )}
                            </>
                        ) : (
                            <p className="text-base mt-1">{leitura.mensagem}</p>
                        )}
                    </div>
                </div>

                <button
                    type="button"
                    onClick={() => onFechar?.()}
                    // Clicar não tira o foco do campo do leitor.
                    onMouseDown={(e) => e.preventDefault()}
                    className={`relative mt-4 w-full overflow-hidden rounded-lg py-2.5 font-semibold text-white ${estilo.botao}`}
                >
                    {someSozinho && (
                        <span
                            key={leitura.id}
                            data-testid="progresso-card"
                            aria-hidden="true"
                            className="absolute inset-y-0 left-0 bg-black/30 fetec-preencher"
                            style={{ animationDuration: `${DURACAO_CARD_MS}ms` }}
                        />
                    )}
                    <span className="relative">Ok</span>
                </button>
            </div>
        </div>
    );
}
