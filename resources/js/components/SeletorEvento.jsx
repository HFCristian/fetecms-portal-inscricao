import { TODOS } from '../lib/eventoCerimonial.js';

/**
 * Escolha do evento de cerimônia: um botão por evento, com o público embaixo.
 *
 * Botões e não um `<select>`: são dois ou três eventos, e o nome de cada um
 * precisa ficar à vista o tempo todo — quem atende a porta confere de relance
 * em que cerimônia está registrando.
 */
export default function SeletorEvento({ eventos, valor, onEscolher, permitirTodos = false, rotulo = 'Evento de cerimônia' }) {
    if (!eventos || eventos.length === 0) return null;

    const opcoes = [
        ...(permitirTodos ? [{ id: TODOS, nome: 'Todos os eventos', publico: 'A soma das cerimônias' }] : []),
        ...eventos,
    ];

    return (
        <div className="mb-4">
            <p className="text-sm font-semibold text-on-surface mb-1.5" id="seletor-evento">{rotulo}</p>
            <div role="radiogroup" aria-labelledby="seletor-evento" className="flex flex-wrap gap-2">
                {opcoes.map((e) => {
                    const ativo = valor === e.id;

                    return (
                        <button
                            key={e.id}
                            type="button"
                            role="radio"
                            aria-checked={ativo}
                            onClick={() => onEscolher(e.id)}
                            className={`text-left rounded-xl border-2 px-4 py-2 transition-colors ${
                                ativo
                                    ? 'border-primary-container bg-primary-container text-on-primary'
                                    : 'border-outline-variant bg-surface-container-lowest text-on-surface hover:border-primary-container'
                            }`}
                        >
                            <span className="block text-sm font-semibold">{e.nome}</span>
                            <span className={`block text-xs ${ativo ? 'text-on-primary/80' : 'text-on-surface-variant'}`}>
                                {e.publico}
                            </span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
