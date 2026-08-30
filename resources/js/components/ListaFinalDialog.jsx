import { useEffect, useMemo, useState } from 'react';
import { Button, Alert } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getOpcoesListaFinal, baixarListaFinal } from '../lib/admin.js';

const inputClass =
    'w-24 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const tipoClass =
    'bg-surface border border-outline-variant rounded-lg px-2 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const PASSOS = ['Categorias', 'Áreas', 'Interior'];

/** Uma cota vazia: nem valor, nem tipo escolhido — não limita nada. */
const VAZIA = { tipo: 'fixo', valor: '' };

const preenchida = (cota) => cota && cota.valor !== '' && cota.valor !== null && cota.valor !== undefined;

/** Converte a cota da tela no formato da API; `null` quando está em branco. */
const paraApi = (cota) => (preenchida(cota) ? { tipo: cota.tipo, valor: Number(cota.valor) } : null);

/**
 * Um campo de cota: o número e o seletor fixo/porcentagem. A porcentagem é
 * sempre sobre o recorte que contém este campo (a categoria para a área, a área
 * para o interior).
 */
function CampoCota({ id, rotulo, detalhe, cota, onChange, placeholder = 'sem limite' }) {
    const atual = cota ?? VAZIA;

    return (
        <div className="flex items-center gap-2 py-1">
            <label htmlFor={id} className="text-sm text-on-surface flex-1 min-w-0">
                <span className="block truncate">{rotulo}</span>
                {detalhe && <span className="block text-xs text-on-surface-variant">{detalhe}</span>}
            </label>
            <input
                id={id}
                type="number"
                inputMode="numeric"
                min={0}
                placeholder={placeholder}
                aria-label={`Quantidade para ${rotulo}`}
                value={atual.valor}
                onChange={(e) => onChange({ ...atual, valor: e.target.value })}
                className={inputClass}
            />
            <select
                aria-label={`Tipo da quantidade para ${rotulo}`}
                value={atual.tipo}
                onChange={(e) => onChange({ ...atual, tipo: e.target.value })}
                className={tipoClass}
            >
                <option value="fixo">nº</option>
                <option value="percentual">%</option>
            </select>
        </div>
    );
}

/**
 * "Gerar lista final" em três passos, na ordem em que a organização decide:
 * quantos projetos por **categoria**, depois quantos por **área** dentro de
 * cada categoria e, por fim, quantos daquela área ficam reservados ao
 * **interior** (cidade que não é a capital do estado).
 *
 * Cada quantidade pode ser número fixo ou porcentagem do recorte de cima —
 * "100 da FUNDECT, 20 de agrárias, 70% desses para o interior". Campo em branco
 * não limita; a reserva do interior é piso, e a vaga que ele não preencher
 * volta para os demais.
 *
 * A reserva do interior só existe nas categorias que a permitem (`permite_interior`
 * — hoje só a FETECMS FUNDECT), e marcar **Lista Final Oficial** registra o
 * recorte: ele vira a lista vigente da edição e define os finalistas.
 */
export default function ListaFinalDialog({ open, onClose }) {
    const [opcoes, setOpcoes] = useState(null);
    const [passo, setPasso] = useState(0);
    const [total, setTotal] = useState(VAZIA);
    // { [categoria]: { cota, areas: { [areaId]: { cota, interior } } } }
    const [cotas, setCotas] = useState({});
    const [gerando, setGerando] = useState(false);
    const [erro, setErro] = useState('');
    // Marcar oficial registra a lista (vira a vigente e define os finalistas).
    const [oficial, setOficial] = useState(false);
    const [nome, setNome] = useState('');

    useEffect(() => {
        if (!open) return;
        setErro('');
        setPasso(0);
        setOficial(false);
        setNome('');
        getOpcoesListaFinal().then(setOpcoes).catch(() => setErro('Não foi possível carregar as opções.'));
    }, [open]);

    // Só as categorias que o admin não zerou entram nos passos seguintes: não
    // faz sentido pedir a área de uma categoria que ficou de fora.
    const categoriasAtivas = useMemo(() => (opcoes?.categorias ?? []).filter((c) => {
        const cota = cotas[c.value]?.cota;
        return !preenchida(cota) || Number(cota.valor) > 0;
    }), [opcoes, cotas]);

    if (!open) return null;

    const setCotaCategoria = (categoria, cota) => setCotas((atual) => ({
        ...atual,
        [categoria]: { ...(atual[categoria] ?? {}), cota },
    }));

    const setCotaArea = (categoria, areaId, campo, cota) => setCotas((atual) => {
        const daCategoria = atual[categoria] ?? {};
        const areas = daCategoria.areas ?? {};
        return {
            ...atual,
            [categoria]: {
                ...daCategoria,
                areas: { ...areas, [areaId]: { ...(areas[areaId] ?? {}), [campo]: cota } },
            },
        };
    });

    /** Áreas com cota definida — são as únicas que podem reservar vaga ao interior. */
    const areasComCota = (categoria) => (opcoes?.categorias ?? [])
        .find((c) => c.value === categoria)?.areas
        .filter((a) => preenchida(cotas[categoria]?.areas?.[a.id]?.cota)) ?? [];

    // Só as categorias que reservam vaga ao interior (hoje, a FUNDECT) entram no
    // passo 3 — nas demais a lista é só por nota.
    const categoriasComInterior = categoriasAtivas.filter((c) => c.permite_interior);

    function montarPayload() {
        const categorias = {};

        for (const c of opcoes.categorias) {
            const daCategoria = cotas[c.value] ?? {};
            const areas = {};

            for (const [areaId, config] of Object.entries(daCategoria.areas ?? {})) {
                const cota = paraApi(config.cota);
                // A reserva do interior só viaja para as categorias que a aceitam.
                const interior = c.permite_interior ? paraApi(config.interior) : null;
                if (cota || interior) areas[areaId] = { cota, interior };
            }

            const cota = paraApi(daCategoria.cota);
            if (cota || Object.keys(areas).length > 0) {
                categorias[c.value] = { cota, areas };
            }
        }

        return {
            total: paraApi(total),
            categorias,
            oficial,
            nome: oficial && nome.trim() !== '' ? nome.trim() : null,
        };
    }

    async function gerar() {
        setGerando(true); setErro('');
        try {
            await baixarListaFinal(montarPayload());
            onClose(oficial);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível gerar a lista. Tente novamente.');
        } finally {
            setGerando(false);
        }
    }

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 max-h-[90vh] overflow-auto">
                <h3 className="font-display text-xl font-semibold text-on-surface mb-1">Gerar lista final</h3>
                <p className="text-sm text-on-surface-variant mb-4">
                    Entram os projetos <strong>mais bem avaliados</strong> que couberem nas quantidades
                    que você definir. Cada quantidade pode ser um <strong>número</strong> ou uma{' '}
                    <strong>porcentagem</strong> do recorte acima dela; em branco não limita nada.
                </p>

                {/* Trilha dos três passos */}
                <ol className="flex items-center gap-2 mb-4 text-xs">
                    {PASSOS.map((nome, i) => (
                        <li key={nome} className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setPasso(i)}
                                className={`px-2 py-1 rounded-full font-semibold ${i === passo
                                    ? 'bg-primary-container text-on-primary'
                                    : 'text-on-surface-variant hover:bg-surface-variant'}`}
                            >
                                {i + 1}. {nome}
                            </button>
                            {i < PASSOS.length - 1 && <span className="text-on-surface-variant">›</span>}
                        </li>
                    ))}
                </ol>

                {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

                {opcoes === null ? (
                    <div className="text-center py-8 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : passo === 0 ? (
                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-1">
                            Quantos projetos em cada categoria?
                        </legend>
                        <p className="text-xs text-on-surface-variant mb-2">
                            A porcentagem é sobre os {opcoes.total_disponivel} projetos avaliados
                            {preenchida(total) ? ' (ou sobre o total que você definir abaixo)' : ''}.
                        </p>

                        {opcoes.categorias.map((c) => (
                            <CampoCota
                                key={c.value}
                                id={`lista-cat-${c.value}`}
                                rotulo={c.label}
                                detalhe={`${c.disponiveis} disponíve${c.disponiveis === 1 ? 'l' : 'is'}`}
                                cota={cotas[c.value]?.cota}
                                onChange={(cota) => setCotaCategoria(c.value, cota)}
                            />
                        ))}

                        <div className="border-t border-outline-variant/40 mt-3 pt-3">
                            <CampoCota
                                id="lista-total"
                                rotulo="Total geral da lista"
                                detalhe={`${opcoes.total_disponivel} projeto(s) avaliados · opcional`}
                                cota={total}
                                onChange={setTotal}
                                placeholder="todos"
                            />
                        </div>
                    </fieldset>
                ) : passo === 1 ? (
                    <div className="space-y-4">
                        <p className="text-xs text-on-surface-variant">
                            Dentro de cada categoria, quantos projetos de cada área. A porcentagem é
                            sobre a cota da categoria.
                        </p>
                        {categoriasAtivas.map((c) => (
                            <fieldset key={c.value}>
                                <legend className="text-sm font-semibold text-primary-container mb-1">{c.label}</legend>
                                {c.areas.map((a) => (
                                    <CampoCota
                                        key={a.id}
                                        id={`lista-area-${c.value}-${a.id}`}
                                        rotulo={a.nome}
                                        detalhe={`${a.disponiveis} disponíve${a.disponiveis === 1 ? 'l' : 'is'}`}
                                        cota={cotas[c.value]?.areas?.[a.id]?.cota}
                                        onChange={(cota) => setCotaArea(c.value, a.id, 'cota', cota)}
                                    />
                                ))}
                            </fieldset>
                        ))}
                    </div>
                ) : (
                    <div className="space-y-4">
                        <p className="text-xs text-on-surface-variant">
                            Quantas das vagas de cada área ficam reservadas a projetos do{' '}
                            <strong>interior</strong> (escola fora da capital). A reserva existe
                            apenas nas categorias que a preveem e nas áreas com cota definida — ela é
                            uma fatia dessa cota. Se não houver projeto do interior suficiente, a
                            vaga volta para os demais.
                        </p>
                        {categoriasComInterior.length === 0 ? (
                            <p className="text-sm text-on-surface-variant">
                                Nenhuma categoria selecionada reserva vagas para o interior.
                            </p>
                        ) : categoriasComInterior.every((c) => areasComCota(c.value).length === 0) ? (
                            <p className="text-sm text-on-surface-variant">
                                Nenhuma área tem cota definida. Volte ao passo 2 para definir as cotas
                                por área antes de reservar vagas para o interior.
                            </p>
                        ) : categoriasComInterior.map((c) => {
                            const areas = areasComCota(c.value);
                            if (areas.length === 0) return null;

                            return (
                                <fieldset key={c.value}>
                                    <legend className="text-sm font-semibold text-primary-container mb-1">{c.label}</legend>
                                    {areas.map((a) => (
                                        <CampoCota
                                            key={a.id}
                                            id={`lista-interior-${c.value}-${a.id}`}
                                            rotulo={a.nome}
                                            detalhe={`${a.interior_disponiveis} do interior disponíve${a.interior_disponiveis === 1 ? 'l' : 'is'}`}
                                            cota={cotas[c.value]?.areas?.[a.id]?.interior}
                                            onChange={(cota) => setCotaArea(c.value, a.id, 'interior', cota)}
                                            placeholder="sem reserva"
                                        />
                                    ))}
                                </fieldset>
                            );
                        })}
                    </div>
                )}

                {/* Oficializar: só no último passo, junto do botão que gera. */}
                {passo === PASSOS.length - 1 && (
                    <div className="mt-4 rounded-lg border border-outline-variant p-3">
                        <label className="flex items-start gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={oficial}
                                onChange={(e) => setOficial(e.target.checked)}
                                className="mt-0.5 w-5 h-5 rounded text-primary-container"
                            />
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold text-on-surface">Lista Final Oficial</span>
                                <span className="block text-xs text-on-surface-variant">
                                    Registra esta lista como a vigente da edição: os projetos e seus
                                    participantes passam a ser os <strong>finalistas</strong> da feira. A
                                    composição pode ser alterada depois, sempre gerando um arquivo novo.
                                </span>
                            </span>
                        </label>
                        {oficial && (
                            <input
                                value={nome}
                                onChange={(e) => setNome(e.target.value)}
                                placeholder="Nome da lista (opcional)"
                                aria-label="Nome da lista"
                                maxLength={120}
                                className="mt-3 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                            />
                        )}
                    </div>
                )}

                <div className="flex justify-between gap-2 pt-4">
                    <Button type="button" variant="outline" onClick={() => onClose(false)}>Cancelar</Button>

                    <div className="flex gap-2">
                        {passo > 0 && (
                            <Button type="button" variant="outline" onClick={() => setPasso(passo - 1)}>
                                Voltar
                            </Button>
                        )}
                        {passo < PASSOS.length - 1 ? (
                            <Button type="button" disabled={opcoes === null} onClick={() => setPasso(passo + 1)}>
                                Continuar
                            </Button>
                        ) : (
                            <Button type="button" loading={gerando} disabled={opcoes === null} onClick={gerar}>
                                <span className="material-symbols-outlined text-[18px]">download</span>
                                {oficial ? 'Gerar e oficializar' : 'Gerar TXT'}
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
