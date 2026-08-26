import { useEffect, useRef, useState } from 'react';
import { Button, Alert } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { definirLimitesAvaliador, definirLimitesProjeto } from '../lib/admin.js';

const numeroClass =
    'w-20 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const numeroOuNulo = (valor) => (valor === '' || valor === null || valor === undefined ? null : Number(valor));

/** Par mínimo/máximo de uma linha (o geral ou o de uma categoria). */
function ParMinMax({ id, rotulo, detalhe, min, max, placeholderMin, placeholderMax, onMin, onMax }) {
    return (
        <div className="flex items-center gap-2 py-1.5 flex-wrap">
            <div className="flex-1 min-w-[10rem]">
                <span className="text-sm text-on-surface">{rotulo}</span>
                {detalhe && <span className="block text-xs text-on-surface-variant">{detalhe}</span>}
            </div>
            <label className="text-xs text-on-surface-variant">
                <span className="block mb-1">Mínimo</span>
                <input
                    id={`${id}-min`}
                    type="number"
                    inputMode="numeric"
                    min={1}
                    placeholder={placeholderMin}
                    aria-label={`Mínimo — ${rotulo}`}
                    value={min ?? ''}
                    onChange={(e) => onMin(e.target.value)}
                    className={numeroClass}
                />
            </label>
            <label className="text-xs text-on-surface-variant">
                <span className="block mb-1">Máximo</span>
                <input
                    id={`${id}-max`}
                    type="number"
                    inputMode="numeric"
                    min={1}
                    placeholder={placeholderMax}
                    aria-label={`Máximo — ${rotulo}`}
                    value={max ?? ''}
                    onChange={(e) => onMax(e.target.value)}
                    className={numeroClass}
                />
            </label>
        </div>
    );
}

/** Casca comum dos dois cards: mensagem, erro e botão de salvar. */
function CardLimites({ titulo, descricao, invalido, aviso, onSalvar, onSalvo, children }) {
    const [salvando, setSalvando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    async function salvar() {
        setSalvando(true); setMsg(''); setErro('');
        try {
            const resp = await onSalvar();
            onSalvo?.(resp.data);
            setMsg(resp.meta?.message || 'Limites salvos.');
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível salvar. Tente novamente.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
            <h2 className="font-display text-primary font-semibold mb-1">{titulo}</h2>
            <div className="text-sm text-on-surface-variant mb-3">{descricao}</div>

            {msg && <div className="mb-3"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

            {children}

            <div className="mt-3">
                <Button type="button" loading={salvando} disabled={invalido} onClick={salvar}>Salvar</Button>
                {aviso && <p className="mt-2 text-sm text-error">{aviso}</p>}
            </div>
        </div>
    );
}

/** Quantas avaliações cada avaliador conclui e até quantas pode acumular. */
export function LimitesAvaliadorCard({ config, onSalvo }) {
    const [min, setMin] = useState(config.min_por_avaliador ?? '');
    const [max, setMax] = useState(config.max_por_avaliador ?? '');

    const anterior = useRef(config);
    useEffect(() => {
        if (anterior.current !== config) {
            anterior.current = config;
            setMin(config.min_por_avaliador ?? '');
            setMax(config.max_por_avaliador ?? '');
        }
    }, [config]);

    const minNum = numeroOuNulo(min);
    const maxNum = numeroOuNulo(max);
    const invalido = minNum === null || minNum < 1 || (maxNum !== null && maxNum < minNum);

    return (
        <CardLimites
            titulo="Avaliações por avaliador"
            descricao={
                <>
                    O <strong>mínimo</strong> é quantas avaliações cada avaliador precisa concluir — e
                    também <strong>quantos projetos ele vê de uma vez</strong> no painel: concluída uma,
                    entra outra no lugar. O <strong>máximo</strong> é o teto de avaliações que ele pode
                    acumular; deixe em branco para não ter teto. O <strong>bloqueio individual</strong> de
                    um avaliador, quando existe, continua valendo por cima destes números.
                </>
            }
            invalido={invalido}
            aviso={invalido && maxNum !== null && minNum !== null ? 'O máximo não pode ser menor que o mínimo.' : ''}
            onSalvar={() => definirLimitesAvaliador(minNum, maxNum)}
            onSalvo={onSalvo}
        >
            <ParMinMax
                id="avaliador"
                rotulo="Por avaliador"
                min={min}
                max={max}
                placeholderMin="3"
                placeholderMax="sem teto"
                onMin={setMin}
                onMax={setMax}
            />
        </CardLimites>
    );
}

/**
 * Mínimo e máximo de avaliações por projeto: um par geral e, se o edital pedir,
 * um par próprio para cada categoria (em branco, a categoria segue o geral).
 */
export function LimitesProjetoCard({ config, onSalvo }) {
    const [min, setMin] = useState(config.min_por_projeto ?? '');
    const [max, setMax] = useState(config.max_por_projeto ?? '');
    const [categorias, setCategorias] = useState(
        Object.fromEntries((config.categorias ?? []).map((c) => [c.value, { min: c.min ?? '', max: c.max ?? '' }])),
    );

    const anterior = useRef(config);
    useEffect(() => {
        if (anterior.current !== config) {
            anterior.current = config;
            setMin(config.min_por_projeto ?? '');
            setMax(config.max_por_projeto ?? '');
            setCategorias(Object.fromEntries(
                (config.categorias ?? []).map((c) => [c.value, { min: c.min ?? '', max: c.max ?? '' }]),
            ));
        }
    }, [config]);

    const minNum = numeroOuNulo(min);
    const maxNum = numeroOuNulo(max);

    const atualizar = (valor, campo, numero) =>
        setCategorias((atual) => ({ ...atual, [valor]: { ...atual[valor], [campo]: numero } }));

    const categoriaInvalida = Object.values(categorias).some((c) => {
        const cMax = numeroOuNulo(c.max);
        const cMin = numeroOuNulo(c.min) ?? minNum;
        return cMax !== null && cMin !== null && cMax < cMin;
    });

    const invalido = minNum === null || minNum < 1 || maxNum === null || maxNum < minNum || categoriaInvalida;

    function salvar() {
        return definirLimitesProjeto(minNum, maxNum, Object.fromEntries(
            Object.entries(categorias).map(([valor, c]) => [valor, {
                min: numeroOuNulo(c.min),
                max: numeroOuNulo(c.max),
            }]),
        ));
    }

    return (
        <CardLimites
            titulo="Avaliações por projeto"
            descricao={
                <>
                    O <strong>mínimo</strong> é o alvo da distribuição automática e a base das colunas de
                    <strong> faltantes</strong>; abaixo dele, a média do projeto no ranking aparece como
                    parcial. O <strong>máximo</strong> é quantos avaliadores enxergam o mesmo projeto. Cada
                    categoria pode ter os seus números — em branco, ela segue os gerais.
                </>
            }
            invalido={invalido}
            aviso={invalido && maxNum !== null && minNum !== null && (maxNum < minNum || categoriaInvalida)
                ? 'O máximo não pode ser menor que o mínimo.' : ''}
            onSalvar={salvar}
            onSalvo={onSalvo}
        >
            <ParMinMax
                id="projeto-geral"
                rotulo="Geral"
                detalhe="Vale para toda categoria que não tiver número próprio"
                min={min}
                max={max}
                placeholderMin="3"
                placeholderMax="5"
                onMin={setMin}
                onMax={setMax}
            />

            <div className="mt-2 pl-4 border-l-2 border-outline-variant/40">
                {(config.categorias ?? []).map((c) => (
                    <ParMinMax
                        key={c.value}
                        id={`projeto-${c.value}`}
                        rotulo={c.label}
                        min={categorias[c.value]?.min ?? ''}
                        max={categorias[c.value]?.max ?? ''}
                        placeholderMin={String(minNum ?? '')}
                        placeholderMax={String(maxNum ?? '')}
                        onMin={(v) => atualizar(c.value, 'min', v)}
                        onMax={(v) => atualizar(c.value, 'max', v)}
                    />
                ))}
            </div>
        </CardLimites>
    );
}
