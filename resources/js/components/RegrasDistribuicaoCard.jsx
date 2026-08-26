import { useEffect, useRef, useState } from 'react';
import { Button, Alert, Toggle } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { definirRegrasDistribuicao } from '../lib/admin.js';

const numeroClass =
    'w-20 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none disabled:opacity-50';

/** Campo em branco vira null (sem teto); o resto vira número. */
const numeroOuNulo = (valor) => (valor === '' || valor === null ? null : Number(valor));

/** A faixa em português, para o admin conferir o que configurou. */
function resumoFaixa(regra) {
    if (!regra.ativa) return 'Fora da distribuição automática.';

    const max = numeroOuNulo(regra.max_concluidas);
    const min = Number(regra.min_concluidas || 0);

    return max === null
        ? `Recebe avaliador com ${min} ou mais avaliações concluídas.`
        : min === max
            ? `Só recebe avaliador com exatamente ${min} ${min === 1 ? 'avaliação concluída' : 'avaliações concluídas'}.`
            : `Recebe avaliador com ${min} a ${max} avaliações concluídas.`;
}

/**
 * Regras do algoritmo de distribuição, uma linha por categoria: se ela entra na
 * distribuição automática e em que faixa de avaliações JÁ CONCLUÍDAS o projeto
 * ainda aceita um avaliador novo. Vale para "Distribuir", para "Redistribuir" e
 * para a reposição da fila do avaliador — a designação manual passa por cima.
 */
export default function RegrasDistribuicaoCard({ config, onSalvo }) {
    const [regras, setRegras] = useState(config.regras);
    const [salvando, setSalvando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');

    // Só sincroniza quando o servidor devolve outra configuração — sem a guarda,
    // o efeito de montagem apagaria o que a pessoa acabou de digitar.
    const anterior = useRef(config.regras);
    useEffect(() => {
        if (anterior.current !== config.regras) {
            anterior.current = config.regras;
            setRegras(config.regras);
        }
    }, [config.regras]);

    const atualizar = (categoria, campo, valor) =>
        setRegras((atual) => ({ ...atual, [categoria]: { ...atual[categoria], [campo]: valor } }));

    // Faixa invertida (até menor que de) não vai para o servidor.
    const invalida = Object.values(regras).some((r) => {
        const max = numeroOuNulo(r.max_concluidas);
        return r.ativa && max !== null && max < Number(r.min_concluidas || 0);
    });

    const nenhumaAtiva = Object.values(regras).every((r) => !r.ativa);

    async function salvar() {
        setSalvando(true); setMsg(''); setErro('');
        try {
            const payload = Object.fromEntries(Object.entries(regras).map(([categoria, r]) => [categoria, {
                ativa: r.ativa,
                min_concluidas: Number(r.min_concluidas || 0),
                max_concluidas: numeroOuNulo(r.max_concluidas),
            }]));
            const resp = await definirRegrasDistribuicao(payload);
            onSalvo?.(resp.data);
            setMsg(resp.meta?.message || 'Regras salvas.');
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível salvar as regras. Tente novamente.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-4 max-w-3xl">
            <h3 className="font-display text-primary font-semibold mb-1">Regras por categoria</h3>
            <p className="text-sm text-on-surface-variant mb-4">
                Quais projetos o algoritmo pode designar. Desligue uma categoria para deixá-la de fora e
                use a faixa para mirar em quem ainda precisa — por exemplo, só FETEC Jr com 0 avaliações
                concluídas. A designação manual do admin não passa por estas regras.
            </p>

            {msg && <div className="mb-3"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
            {nenhumaAtiva && (
                <div className="mb-3">
                    <Alert>Com todas as categorias desligadas, nenhum projeto entra na distribuição automática.</Alert>
                </div>
            )}

            <div className="space-y-3 mb-4">
                {config.categorias.map(({ value, label }) => {
                    const regra = regras[value];
                    if (!regra) return null;

                    return (
                        <div key={value} className="border border-outline-variant rounded-lg p-4">
                            <Toggle
                                checked={regra.ativa}
                                onChange={(v) => atualizar(value, 'ativa', v)}
                                label={label}
                                description="Entra na distribuição automática"
                            />

                            <div className="mt-3 flex items-end gap-3 flex-wrap">
                                <label className="text-xs text-on-surface-variant">
                                    <span className="block mb-1">De</span>
                                    <input
                                        type="number"
                                        inputMode="numeric"
                                        min={0}
                                        max={config.max_concluidas}
                                        disabled={!regra.ativa}
                                        aria-label={`Mínimo de avaliações concluídas — ${label}`}
                                        value={regra.min_concluidas ?? 0}
                                        onChange={(e) => atualizar(value, 'min_concluidas', e.target.value)}
                                        className={numeroClass}
                                    />
                                </label>
                                <label className="text-xs text-on-surface-variant">
                                    <span className="block mb-1">Até</span>
                                    <input
                                        type="number"
                                        inputMode="numeric"
                                        min={0}
                                        max={config.max_concluidas}
                                        disabled={!regra.ativa}
                                        placeholder="—"
                                        aria-label={`Máximo de avaliações concluídas — ${label}`}
                                        value={regra.max_concluidas ?? ''}
                                        onChange={(e) => atualizar(value, 'max_concluidas', e.target.value)}
                                        className={numeroClass}
                                    />
                                </label>
                                <p className="text-xs text-on-surface-variant flex-1 min-w-[12rem] pb-2">
                                    avaliações concluídas — deixe <strong>Até</strong> em branco para não ter teto.
                                    <span className="block text-on-surface-variant/80 mt-0.5">{resumoFaixa(regra)}</span>
                                </p>
                            </div>
                        </div>
                    );
                })}
            </div>

            <Button type="button" loading={salvando} disabled={invalida} onClick={salvar}>
                Salvar regras
            </Button>
            {invalida && (
                <p className="mt-2 text-sm text-error">O valor de <strong>Até</strong> não pode ser menor que o de <strong>De</strong>.</p>
            )}
        </div>
    );
}
