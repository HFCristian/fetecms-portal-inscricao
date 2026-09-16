import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getConfigPresencial, getChecagens, getFichaEstande, registrarChecagem, urlTermo,
} from '../lib/presencial.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

const local = (l) =>
    !l || (!l.estande && !l.turno_label)
        ? 'Estande ainda não definido'
        : [l.estande ? `Estande ${l.estande}` : null, l.turno_label].filter(Boolean).join(' · ');

/**
 * A ficha de um estande: os itens conferidos um a um, o termo de
 * responsabilidade que o orientador enviou e a observação de quem conferiu.
 */
function Ficha({ ficha, situacoes, aberto, salvando, erro, onSalvar, onVoltar }) {
    const [marcado, setMarcado] = useState(
        Object.fromEntries((ficha.itens ?? []).map((i) => [i.id, i.situacao ?? ''])),
    );
    const [observacao, setObservacao] = useState(ficha.checagem?.observacao ?? '');

    return (
        <div className="max-w-3xl space-y-4">
            <button type="button" onClick={onVoltar} className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Estandes
            </button>

            {erro && <Alert>{erro}</Alert>}

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                <h2 className="font-display text-lg font-semibold text-on-surface">{ficha.projeto.titulo}</h2>
                <p className="text-sm text-on-surface-variant">
                    {[ficha.projeto.categoria, ficha.projeto.area, ficha.projeto.escola].filter(Boolean).join(' · ')}
                </p>
                <p className="text-sm font-semibold text-primary-container mt-1">{local(ficha.local)}</p>
                <p className="text-xs text-on-surface-variant mt-1">
                    {ficha.projeto.orientador} · {ficha.projeto.alunos.join(', ')}
                </p>
                {ficha.checagem?.verificado_em && (
                    <p className="text-xs text-on-surface-variant mt-2">
                        Conferido em {dataHora(ficha.checagem.verificado_em)}
                        {ficha.checagem.verificado_por ? ` por ${ficha.checagem.verificado_por}` : ''}
                    </p>
                )}
            </div>

            {/* O termo vem do orientador: aqui ele é conferido e lido, não enviado. */}
            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                <h3 className="font-display text-base font-semibold text-on-surface mb-2">
                    Termo de responsabilidade
                </h3>
                {ficha.termo ? (
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="text-xs font-semibold px-2 py-1 rounded-full bg-secondary-container text-on-secondary-container">
                            Presente
                        </span>
                        <span className="text-sm text-on-surface">{ficha.termo.nome_original}</span>
                        <a
                            href={urlTermo(ficha.termo.id)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline"
                        >
                            <span className="material-symbols-outlined text-[18px]">open_in_new</span>
                            Ver PDF
                        </a>
                        <span className="w-full text-xs text-on-surface-variant">
                            {ficha.termo.assinatura_motivo}
                        </span>
                    </div>
                ) : (
                    <p className="text-sm text-error font-semibold">
                        Ausente — o orientador ainda não enviou o termo.
                    </p>
                )}
            </section>

            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                <h3 className="font-display text-base font-semibold text-on-surface mb-2">
                    Itens conferidos
                </h3>
                {ficha.itens.length === 0 ? (
                    <p className="text-sm text-on-surface-variant">
                        Nenhum item cadastrado. Configure a lista do que se confere em cada estande.
                    </p>
                ) : (
                    <ul className="divide-y divide-outline-variant/30">
                        {ficha.itens.map((item) => (
                            <li key={item.id} className="py-3 flex flex-wrap items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm text-on-surface">{item.nome}</p>
                                    {item.descricao && (
                                        <p className="text-xs text-on-surface-variant">{item.descricao}</p>
                                    )}
                                </div>
                                <select
                                    className={`${campoClass} w-44`}
                                    aria-label={`Situação de ${item.nome}`}
                                    disabled={!aberto}
                                    value={marcado[item.id] ?? ''}
                                    onChange={(e) => setMarcado((m) => ({ ...m, [item.id]: e.target.value }))}
                                >
                                    <option value="">Não conferido</option>
                                    {situacoes.map((s) => (
                                        <option key={s.value} value={s.value}>{s.label}</option>
                                    ))}
                                </select>
                            </li>
                        ))}
                    </ul>
                )}

                <label className="block mt-4">
                    <span className="text-sm font-semibold text-on-surface">Observação</span>
                    <textarea
                        rows={3}
                        maxLength={1000}
                        disabled={!aberto}
                        value={observacao}
                        onChange={(e) => setObservacao(e.target.value)}
                        className={`${campoClass} mt-1`}
                    />
                </label>

                {aberto && (
                    <div className="flex justify-end mt-4">
                        <Button
                            type="button"
                            loading={salvando}
                            onClick={() => onSalvar({
                                itens: Object.entries(marcado)
                                    .filter(([, situacao]) => situacao !== '')
                                    .map(([item_id, situacao]) => ({ item_id: Number(item_id), situacao })),
                                observacao: observacao.trim() || null,
                            })}
                        >
                            <span className="material-symbols-outlined text-[20px]">task_alt</span>
                            Registrar checagem
                        </Button>
                    </div>
                )}
            </section>
        </div>
    );
}

/**
 * Avaliação presencial → Checagem de estandes.
 *
 * A lista dos finalistas com a situação de cada estande e, ao abrir um deles, a
 * ficha de conferência. Fora da janela do evento a tela abre em leitura — o que
 * já foi conferido continua visível.
 */
export default function PresencialChecagem() {
    const [config, setConfig] = useState(null);
    const [modoTeste, setModoTeste] = useState(false);
    const [filtros, setFiltros] = useState({ busca: '', area_id: '', categoria: '', situacao: '', turno: '' });
    const [lista, setLista] = useState(null);
    const [meta, setMeta] = useState(null);
    const [ficha, setFicha] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [alert, setAlert] = useState('');
    const [sucesso, setSucesso] = useState('');

    useEffect(() => {
        getConfigPresencial(modoTeste).then(setConfig).catch(() => setAlert('Não foi possível carregar a configuração.'));
    }, [modoTeste]);

    const carregar = useCallback(() => {
        setLista(null);
        return getChecagens(filtros, modoTeste)
            .then((r) => { setLista(r.data); setMeta(r.meta); })
            .catch((e) => { setLista([]); setAlert(extractErrors(e).message); });
    }, [filtros, modoTeste]);

    useEffect(() => { carregar(); }, [carregar]);

    async function abrir(projeto) {
        setAlert(''); setSucesso('');
        try {
            setFicha(await getFichaEstande(projeto.id, modoTeste));
        } catch (e) {
            setAlert(extractErrors(e).message || 'Não foi possível abrir a ficha.');
        }
    }

    async function salvar(dados) {
        setSalvando(true); setAlert(''); setSucesso('');
        try {
            const resp = await registrarChecagem(ficha.projeto.id, dados, modoTeste);
            setFicha(resp.data);
            setSucesso(resp.meta?.message ?? 'Checagem registrada.');
            await carregar();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlert(Object.values(fields ?? {})[0] || message || 'Não foi possível registrar.');
        } finally {
            setSalvando(false);
        }
    }

    const filtrar = (campo, valor) => setFiltros((f) => ({ ...f, [campo]: valor }));
    const resumo = meta?.resumo;

    return (
        <AppShell>
            <Link to="/admin/presencial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação presencial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Checagem de estandes</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Os finalistas e o estande de cada um. Abra um projeto para conferir os itens no local
                e registrar o que encontrou.
            </p>

            {config?.pode_testar && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={(v) => { setModoTeste(v); setFicha(null); }}
                        label="Modo de teste"
                        description="Conta demo: usa a lista final de demonstração e ignora as datas do evento."
                    />
                </div>
            )}

            <div className="max-w-3xl space-y-3 mb-4">
                <Alert>{alert}</Alert>
                <Alert type="info">{sucesso}</Alert>
                {config && !config.aberto && <Alert type="info">{config.motivo_fechado}</Alert>}
            </div>

            {ficha ? (
                <Ficha
                    key={ficha.projeto.id}
                    ficha={ficha}
                    situacoes={config?.situacoes ?? []}
                    aberto={Boolean(config?.aberto)}
                    salvando={salvando}
                    erro={alert}
                    onSalvar={salvar}
                    onVoltar={() => { setFicha(null); setSucesso(''); }}
                />
            ) : (
                <div className="max-w-4xl">
                    {resumo && (
                        <div className="grid grid-cols-3 gap-3 mb-4">
                            {[
                                ['Finalistas', resumo.finalistas],
                                ['Conferidos', resumo.conferidos],
                                ['Pendentes', resumo.pendentes],
                            ].map(([rotulo, valor]) => (
                                <div key={rotulo} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-3 text-center">
                                    <div className="text-2xl font-bold text-primary">{valor}</div>
                                    <div className="text-xs text-on-surface-variant">{rotulo}</div>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
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

                    {lista === null ? (
                        <div className="text-center py-10 text-on-surface-variant">
                            <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                        </div>
                    ) : lista.length === 0 ? (
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant">
                            Nenhum estande neste recorte.
                        </div>
                    ) : (
                        <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40">
                            {lista.map((p) => (
                                <li key={p.id} className="p-4 flex flex-wrap items-center gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold text-on-surface truncate">{p.titulo}</p>
                                        <p className="text-xs text-on-surface-variant truncate">
                                            {[p.area, p.escola, p.orientador].filter(Boolean).join(' · ')}
                                        </p>
                                        <p className="text-xs text-primary-container font-semibold">{local(p.local)}</p>
                                    </div>
                                    <div className="flex items-center gap-2 shrink-0">
                                        {p.conferido ? (
                                            <span className="text-xs font-semibold px-2 py-1 rounded-full bg-secondary-container text-on-secondary-container">
                                                Conferido
                                            </span>
                                        ) : (
                                            <span className="text-xs font-semibold px-2 py-1 rounded-full bg-surface-variant text-on-surface-variant">
                                                Pendente
                                            </span>
                                        )}
                                        {p.ausentes > 0 && (
                                            <span className="text-xs font-semibold px-2 py-1 rounded-full bg-error-container/50 text-on-surface">
                                                {p.ausentes} ausente(s)
                                            </span>
                                        )}
                                        {!p.tem_termo && (
                                            <span className="text-xs font-semibold px-2 py-1 rounded-full bg-error-container/50 text-on-surface">
                                                sem termo
                                            </span>
                                        )}
                                        <Button type="button" variant="outline" onClick={() => abrir(p)}>
                                            {config?.aberto ? 'Conferir' : 'Ver'}
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </AppShell>
    );
}
