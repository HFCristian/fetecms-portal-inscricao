import { useMemo, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { baixarProjetosPesquisa } from '../lib/admin.js';

/**
 * Uma caixa de seleção que também sabe o estado "parcial" — a categoria com
 * metade dos projetos marcados não está nem marcada nem desmarcada.
 */
function CaixaGrupo({ ids, selecionados, onAlternar, children, className = '' }) {
    const marcados = ids.filter((id) => selecionados.has(id)).length;
    const todos = ids.length > 0 && marcados === ids.length;

    return (
        <label className={`inline-flex items-center gap-2 cursor-pointer ${className}`}>
            <input
                type="checkbox"
                className="accent-primary-container"
                checked={todos}
                ref={(el) => { if (el) el.indeterminate = marcados > 0 && !todos; }}
                onChange={() => onAlternar(ids, !todos)}
            />
            {children}
        </label>
    );
}

/** Os itens agrupados por categoria+área, na ordem da lista. */
function agrupar(itens) {
    const grupos = [];
    const porChave = {};

    for (const item of itens) {
        const chave = `${item.categoria ?? ''}|${item.area ?? ''}`;
        if (!porChave[chave]) {
            porChave[chave] = { chave, categoria: item.categoria, area: item.area, itens: [] };
            grupos.push(porChave[chave]);
        }
        porChave[chave].itens.push(item);
    }

    return grupos;
}

/** Os ids de cada valor de um campo (categoria ou área), na ordem em que aparecem. */
function idsPor(itens, campo) {
    const mapa = new Map();
    for (const item of itens) {
        const valor = item[campo] || (campo === 'area' ? 'Sem área' : 'Sem categoria');
        if (!mapa.has(valor)) mapa.set(valor, []);
        mapa.get(valor).push(item.projeto_id);
    }
    return [...mapa.entries()];
}

/**
 * Lista final → **Projetos de pesquisa** (Sprint 187): o arquivo do projeto de
 * pesquisa de cada finalista num ZIP, em pastas por categoria e área, com o
 * código do projeto no nome.
 *
 * Baixa a lista inteira de uma vez ou só o que for marcado — projeto a projeto,
 * ou de uma vez por **categoria** ou por **área**, que é como a organização
 * divide o material das bancas. As marcações por categoria e por área se
 * somam: marcar FETEC Jr e depois Ciências Agrárias leva as duas coisas.
 */
export default function ExportarProjetosPesquisa({ listaId, itens }) {
    const [escolhendo, setEscolhendo] = useState(false);
    const [selecionados, setSelecionados] = useState(() => new Set());
    const [baixando, setBaixando] = useState(null);
    const [erro, setErro] = useState('');

    const categorias = useMemo(() => idsPor(itens, 'categoria'), [itens]);
    const areas = useMemo(() => idsPor(itens, 'area').sort(([a], [b]) => a.localeCompare(b, 'pt-BR')), [itens]);
    const grupos = useMemo(() => agrupar(itens), [itens]);

    // Projeto que saiu da lista depois de marcado não vai no pedido.
    const escolhidos = itens.map((i) => i.projeto_id).filter((id) => selecionados.has(id));

    function alternar(ids, marcar) {
        setSelecionados((atual) => {
            const novo = new Set(atual);
            ids.forEach((id) => (marcar ? novo.add(id) : novo.delete(id)));
            return novo;
        });
    }

    async function baixar(chave, ids) {
        setBaixando(chave);
        setErro('');
        try {
            await baixarProjetosPesquisa(listaId, ids);
        } catch (e) {
            setErro(e.message || 'Não foi possível montar o arquivo.');
        } finally {
            setBaixando(null);
        }
    }

    if (itens.length === 0) return null;

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mt-6 max-w-3xl" aria-label="Projetos de pesquisa">
            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Projetos de pesquisa</h2>
            <p className="text-sm text-on-surface-variant mb-3">
                O arquivo do projeto de pesquisa de cada projeto da lista num ZIP, em pastas por categoria e
                área e com o código do projeto no nome. Quem não tiver o arquivo aparece num aviso dentro do ZIP.
            </p>

            <div className="flex flex-wrap gap-2">
                <Button type="button" loading={baixando === 'todos'} disabled={baixando !== null} onClick={() => baixar('todos', null)}>
                    <span className="material-symbols-outlined text-[20px]">folder_zip</span>
                    Baixar todos ({itens.length})
                </Button>
                <Button type="button" variant="outline" onClick={() => setEscolhendo((e) => !e)} aria-expanded={escolhendo}>
                    <span className="material-symbols-outlined text-[20px]">{escolhendo ? 'expand_less' : 'checklist'}</span>
                    {escolhendo ? 'Fechar seleção' : 'Escolher projetos'}
                </Button>
            </div>

            {erro && <div className="mt-3"><Alert>{erro}</Alert></div>}

            {escolhendo && (
                <div className="mt-4 space-y-4">
                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-1">Por categoria</legend>
                        <div className="flex flex-wrap gap-x-4 gap-y-1">
                            {categorias.map(([nome, ids]) => (
                                <CaixaGrupo key={nome} ids={ids} selecionados={selecionados} onAlternar={alternar} className="text-sm">
                                    {nome} <span className="text-on-surface-variant">({ids.length})</span>
                                </CaixaGrupo>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-1">Por área do conhecimento</legend>
                        <div className="flex flex-wrap gap-x-4 gap-y-1">
                            {areas.map(([nome, ids]) => (
                                <CaixaGrupo key={nome} ids={ids} selecionados={selecionados} onAlternar={alternar} className="text-sm">
                                    {nome} <span className="text-on-surface-variant">({ids.length})</span>
                                </CaixaGrupo>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-1">Projeto a projeto</legend>
                        <div className="max-h-96 overflow-y-auto fetec-scroll border border-outline-variant/40 rounded-lg divide-y divide-outline-variant/30">
                            {grupos.map((grupo) => (
                                <div key={grupo.chave} className="p-3">
                                    <CaixaGrupo
                                        ids={grupo.itens.map((i) => i.projeto_id)}
                                        selecionados={selecionados}
                                        onAlternar={alternar}
                                        className="text-sm font-semibold text-on-surface"
                                    >
                                        {grupo.categoria || 'Sem categoria'} · {grupo.area || 'Sem área'}
                                    </CaixaGrupo>
                                    <ul className="mt-1 ml-6 space-y-0.5">
                                        {grupo.itens.map((item) => (
                                            <li key={item.projeto_id}>
                                                <label className="inline-flex items-start gap-2 text-sm cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        className="accent-primary-container mt-1"
                                                        checked={selecionados.has(item.projeto_id)}
                                                        onChange={(e) => alternar([item.projeto_id], e.target.checked)}
                                                    />
                                                    <span>
                                                        {item.codigo && <code className="text-xs text-primary-container mr-1">{item.codigo}</code>}
                                                        {item.titulo}
                                                    </span>
                                                </label>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </fieldset>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            loading={baixando === 'selecionados'}
                            disabled={escolhidos.length === 0 || baixando !== null}
                            onClick={() => baixar('selecionados', escolhidos)}
                        >
                            <span className="material-symbols-outlined text-[20px]">download</span>
                            Baixar selecionados ({escolhidos.length})
                        </Button>
                        {escolhidos.length > 0 && (
                            <button type="button" className="text-sm text-primary hover:underline" onClick={() => setSelecionados(new Set())}>
                                Limpar seleção
                            </button>
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}
