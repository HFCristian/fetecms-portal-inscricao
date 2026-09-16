import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getConfigPresencial, getEspelhoChecagem, urlTermo } from '../lib/presencial.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

// Como cada situação aparece na célula. "Não conferido" é o vazio: ninguém
// passou por ali ainda, que é diferente de "não necessário".
const MARCA = {
    presente: { icone: 'check_circle', classe: 'text-secondary', titulo: 'Presente' },
    ausente: { icone: 'cancel', classe: 'text-error', titulo: 'Ausente' },
    nao_necessario: { icone: 'remove_circle', classe: 'text-on-surface-variant', titulo: 'Não necessário' },
};

function Marca({ situacao }) {
    const m = MARCA[situacao];

    if (!m) {
        return <span className="text-on-surface-variant/50" title="Não conferido">—</span>;
    }

    return (
        <span className={`material-symbols-outlined text-[20px] ${m.classe}`} title={m.titulo} aria-label={m.titulo}>
            {m.icone}
        </span>
    );
}

/**
 * Avaliação presencial → **Espelho da checagem**.
 *
 * A mesma informação das fichas, lado a lado: cada finalista numa linha, cada
 * item numa coluna, mais o termo de responsabilidade. É a tela que responde "o
 * que ainda falta?" sem abrir projeto por projeto — e é por isso que o termo
 * também aparece aqui, com o link do PDF.
 */
export default function PresencialEspelho() {
    const [config, setConfig] = useState(null);
    const [modoTeste, setModoTeste] = useState(false);
    const [filtros, setFiltros] = useState({ busca: '', area_id: '', categoria: '', situacao: '' });
    const [linhas, setLinhas] = useState(null);
    const [itens, setItens] = useState([]);
    const [alert, setAlert] = useState('');

    useEffect(() => {
        getConfigPresencial(modoTeste).then(setConfig).catch(() => {});
    }, [modoTeste]);

    const carregar = useCallback(() => {
        setLinhas(null);
        return getEspelhoChecagem(filtros, modoTeste)
            .then((r) => { setLinhas(r.data); setItens(r.meta?.itens?.filter((i) => i.ativo) ?? []); })
            .catch((e) => { setLinhas([]); setAlert(extractErrors(e).message); });
    }, [filtros, modoTeste]);

    useEffect(() => { carregar(); }, [carregar]);

    const filtrar = (campo, valor) => setFiltros((f) => ({ ...f, [campo]: valor }));

    return (
        <AppShell>
            <Link to="/admin/presencial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação presencial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Espelho da checagem</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Todos os finalistas com o que foi conferido em cada estande e o termo de
                responsabilidade de cada um — para ver o que falta sem abrir projeto por projeto.
            </p>

            {config?.pode_testar && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Conta demo: usa a lista final de demonstração."
                    />
                </div>
            )}

            <div className="max-w-3xl mb-4"><Alert>{alert}</Alert></div>

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 max-w-4xl">
                <input
                    className={campoClass}
                    placeholder="Buscar por projeto, escola ou orientador…"
                    aria-label="Buscar"
                    value={filtros.busca}
                    onChange={(e) => filtrar('busca', e.target.value)}
                />
                <select className={campoClass} aria-label="Área" value={filtros.area_id} onChange={(e) => filtrar('area_id', e.target.value)}>
                    <option value="">Todas as áreas</option>
                    {(config?.areas ?? []).map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                </select>
                <select className={campoClass} aria-label="Categoria" value={filtros.categoria} onChange={(e) => filtrar('categoria', e.target.value)}>
                    <option value="">Todas as categorias</option>
                    {(config?.categorias ?? []).map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </select>
                <select className={campoClass} aria-label="Situação" value={filtros.situacao} onChange={(e) => filtrar('situacao', e.target.value)}>
                    <option value="">Todos</option>
                    <option value="pendentes">Pendentes</option>
                    <option value="conferidos">Conferidos</option>
                </select>
            </div>

            {linhas === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : linhas.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-4xl">
                    Nenhum finalista neste recorte.
                </div>
            ) : (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="bg-surface-variant/40 text-left">
                                <th className="px-4 py-3 font-semibold text-on-surface">Projeto</th>
                                <th className="px-3 py-3 font-semibold text-on-surface">Estande</th>
                                <th className="px-3 py-3 font-semibold text-on-surface">Termo</th>
                                {itens.map((i) => (
                                    <th key={i.id} className="px-3 py-3 font-semibold text-on-surface text-center whitespace-nowrap">
                                        {i.nome}
                                    </th>
                                ))}
                                <th className="px-3 py-3 font-semibold text-on-surface">Conferido</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-outline-variant/30">
                            {linhas.map((l) => (
                                <tr key={l.id}>
                                    <td className="px-4 py-3">
                                        <p className="font-semibold text-on-surface">{l.titulo}</p>
                                        <p className="text-xs text-on-surface-variant">
                                            {[l.area, l.escola, l.orientador].filter(Boolean).join(' · ')}
                                        </p>
                                        {l.observacao && (
                                            <p className="text-xs text-on-surface-variant italic mt-1">{l.observacao}</p>
                                        )}
                                    </td>
                                    <td className="px-3 py-3 whitespace-nowrap text-on-surface-variant">
                                        {l.local?.estande ?? '—'}
                                        {l.local?.turno_label ? <span className="block text-xs">{l.local.turno_label}</span> : null}
                                    </td>
                                    <td className="px-3 py-3 whitespace-nowrap">
                                        {l.termo ? (
                                            <a
                                                href={urlTermo(l.termo.id)}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 text-primary hover:underline"
                                                title={l.termo.assinatura_motivo}
                                            >
                                                <span className="material-symbols-outlined text-[18px]">picture_as_pdf</span>
                                                Ver
                                            </a>
                                        ) : (
                                            <span className="text-error font-semibold text-xs">falta</span>
                                        )}
                                    </td>
                                    {itens.map((i) => (
                                        <td key={i.id} className="px-3 py-3 text-center">
                                            <Marca situacao={(l.itens ?? []).find((x) => x.id === i.id)?.situacao} />
                                        </td>
                                    ))}
                                    <td className="px-3 py-3 whitespace-nowrap text-xs text-on-surface-variant">
                                        {l.conferido_em ? (
                                            <>
                                                {dataHora(l.conferido_em)}
                                                {l.conferido_por ? <span className="block">{l.conferido_por}</span> : null}
                                            </>
                                        ) : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppShell>
    );
}
