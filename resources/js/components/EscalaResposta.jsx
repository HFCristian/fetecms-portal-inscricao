/**
 * Escala de resposta de uma pergunta da rubrica: um botão de rádio por ponto,
 * do "Não possui" ao "Muito bom". Os pontos e rótulos vêm da API (o backend é
 * a fonte única da rubrica), então este componente só desenha o que recebe.
 */
export default function EscalaResposta({ nome, escala, valor, onChange, legenda, erro }) {
    return (
        <fieldset>
            <legend className="sr-only">{legenda}</legend>

            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-1.5" role="radiogroup" aria-label={legenda}>
                {escala.map((ponto) => {
                    const selecionado = String(valor) === String(ponto.valor);

                    return (
                        <label
                            key={ponto.valor}
                            className={`flex flex-col items-center gap-1 rounded-lg border px-2 py-2 cursor-pointer text-center transition-colors ${
                                selecionado
                                    ? 'border-primary-container bg-primary-fixed/60 text-primary-container'
                                    : 'border-outline-variant hover:bg-surface-variant/40 text-on-surface-variant'
                            }`}
                        >
                            <input
                                type="radio"
                                name={nome}
                                value={ponto.valor}
                                checked={selecionado}
                                onChange={() => onChange(ponto.valor)}
                                aria-label={`${ponto.rotulo} (${ponto.valor})`}
                                className="w-4 h-4 shrink-0"
                            />
                            <span className="text-sm font-semibold">{ponto.valor}</span>
                            <span className="text-[11px] leading-tight">{ponto.rotulo}</span>
                        </label>
                    );
                })}
            </div>

            {erro && <p className="text-xs text-error mt-1">{erro}</p>}
        </fieldset>
    );
}
