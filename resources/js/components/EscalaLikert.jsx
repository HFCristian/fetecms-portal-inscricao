/**
 * Escala Likert de um quesito da rubrica: um botão de rádio por ponto, do mais
 * insatisfeito ao mais satisfeito. Os pontos e rótulos vêm da API (o backend é
 * a fonte única da escala), então este componente só desenha o que recebe.
 */
export default function EscalaLikert({ nome, escala, valor, onChange, legenda, erro }) {
    return (
        <fieldset>
            <legend className="text-sm text-on-surface-variant mb-2">
                {legenda} <span className="text-error">*</span>
            </legend>

            <div className="grid grid-cols-1 sm:grid-cols-5 gap-1.5" role="radiogroup" aria-label={legenda}>
                {escala.map((ponto) => {
                    const selecionado = String(valor) === String(ponto.valor);

                    return (
                        <label
                            key={ponto.valor}
                            className={`flex sm:flex-col items-center gap-2 sm:gap-1 rounded-lg border px-2 py-2 cursor-pointer text-center transition-colors ${
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
                                onChange={() => onChange(String(ponto.valor))}
                                aria-label={`${ponto.valor} — ${ponto.rotulo}`}
                                className="w-4 h-4 shrink-0"
                            />
                            <span className="text-sm font-semibold">{ponto.valor}</span>
                            <span className="text-xs leading-tight">{ponto.rotulo}</span>
                        </label>
                    );
                })}
            </div>

            {erro && <p className="text-xs text-error mt-1">{erro}</p>}
        </fieldset>
    );
}
