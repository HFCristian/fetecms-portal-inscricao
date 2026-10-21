import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import ListaPresencialDialog from '../components/ListaPresencialDialog.jsx';
import { Alert, Button, Toggle } from '../components/ui.jsx';
import { extractErrors, useAuth } from '../lib/auth.jsx';
import {
    exportarListaPresencial, getListasPresenciais, montarListaPresencialPorUniao, reativarListaPresencial,
} from '../lib/listasPresenciais.js';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

const pill = 'ml-2 align-middle text-xs font-semibold px-2 py-0.5 rounded-full';

const linkClass = 'inline-flex items-center gap-2 rounded-lg border border-outline-variant px-4 py-2.5 text-sm font-semibold text-on-surface hover:bg-surface-variant transition-colors';

function Selos({ lista }) {
    return (
        <>
            {lista.vigente && <span className={`${pill} bg-secondary-container text-on-secondary-container`}>ativa</span>}
            {lista.rascunho && <span className={`${pill} bg-surface-variant text-on-surface-variant`}>rascunho</span>}
            {lista.tipo === 'final' && !lista.rascunho && !lista.vigente && (
                <span className={`${pill} bg-surface-variant text-on-surface-variant`}>inativa</span>
            )}
        </>
    );
}

function LinhaLista({ lista, baixando, onBaixar, onReativar }) {
    return (
        <li className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-on-surface">{lista.nome}<Selos lista={lista} /></p>
                {lista.descricao && <p className="text-sm text-on-surface whitespace-pre-line">{lista.descricao}</p>}
                <p className="text-xs text-on-surface-variant">
                    versão {lista.versao} · {lista.projetos} {lista.projetos === 1 ? 'projeto por nota' : 'projetos por nota'}
                    {' '}· {lista.premiacoes ?? 0} premiação(ões)
                    {lista.gerada_por ? ` · por ${lista.gerada_por}` : ''}
                    {lista.gerada_em ? ` · gerada em ${dataHora(lista.gerada_em)}` : ` · criada em ${dataHora(lista.criada_em)}`}
                </p>
                {lista.origens?.length > 0 && (
                    <p className="text-xs text-on-surface-variant">Montada das listas: {lista.origens.map((o) => o.nome).join(', ')}</p>
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                <Link to={`/admin/presencial/listas/${lista.id}`} className={linkClass}>
                    <span className="material-symbols-outlined text-[20px]">edit_note</span>
                    {lista.rascunho ? 'Editar e gerar' : 'Abrir'}
                </Link>
                {lista.tipo === 'final' && !lista.rascunho && !lista.vigente && (
                    <Button type="button" variant="outline" onClick={() => onReativar(lista)}>
                        <span className="material-symbols-outlined text-[20px]">restart_alt</span>
                        Tornar ativa
                    </Button>
                )}
                <Button type="button" variant="outline" loading={baixando === lista.id} onClick={() => onBaixar(lista.id)}>
                    <span className="material-symbols-outlined text-[20px]">download</span>
                    TXT
                </Button>
            </div>
        </li>
    );
}

/** A união de listas presenciais já geradas, num rascunho preliminar ou final. */
function MontarListaDialog({ finais, preliminares, salvando, erro, onConfirmar, onFechar }) {
    const [marcadas, setMarcadas] = useState([]);
    const [tipo, setTipo] = useState('final');
    const [nome, setNome] = useState('');
    const [descricao, setDescricao] = useState('');
    const alternar = (id) => setMarcadas((m) => (m.includes(id) ? m.filter((x) => x !== id) : [...m, id]));

    const grupo = (titulo, listas) => listas.length > 0 && (
        <div>
            <p className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide mb-1">{titulo}</p>
            <ul className="space-y-1">
                {listas.map((l) => (
                    <li key={l.id}>
                        <label className="flex items-start gap-2 text-sm cursor-pointer">
                            <input type="checkbox" className="mt-1" checked={marcadas.includes(l.id)} onChange={() => alternar(l.id)} aria-label={l.nome} />
                            <span>
                                {l.nome}<Selos lista={l} />
                                <span className="block text-xs text-on-surface-variant">
                                    {l.projetos} projeto(s){l.descricao ? ` · ${l.descricao}` : ''}
                                </span>
                            </span>
                        </label>
                    </li>
                ))}
            </ul>
        </div>
    );

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Montar lista a partir de outras">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Montar lista a partir de outras</h3>
                    <p className="text-sm text-on-surface-variant">
                        A nova lista reúne os premiados das listas marcadas, sem repetir, e abre como rascunho.
                    </p>
                </div>
                {erro && <Alert>{erro}</Alert>}
                <fieldset>
                    <legend className="text-sm font-semibold text-on-surface mb-1">Montar como</legend>
                    <div className="flex flex-wrap gap-4 text-sm">
                        {[['final', 'Lista final'], ['preliminar', 'Lista preliminar']].map(([valor, rotulo]) => (
                            <label key={valor} className="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="tipo-uniao-presencial" value={valor} checked={tipo === valor} onChange={() => setTipo(valor)} />
                                {rotulo}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <div className="space-y-3">
                    {grupo('Listas finais', finais)}
                    {grupo('Listas preliminares', preliminares)}
                </div>
                <input
                    value={nome}
                    onChange={(e) => setNome(e.target.value)}
                    placeholder="Apelido da lista (opcional)"
                    aria-label="Apelido da lista"
                    maxLength={120}
                    className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm"
                />
                <textarea
                    value={descricao}
                    onChange={(e) => setDescricao(e.target.value)}
                    placeholder="Descrição: o que esta lista reúne (opcional)"
                    aria-label="Descrição da lista"
                    maxLength={1000}
                    rows={2}
                    className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm"
                />
                <div className="flex justify-end gap-3">
                    <Button type="button" variant="outline" onClick={onFechar} disabled={salvando}>Cancelar</Button>
                    <Button
                        type="button"
                        loading={salvando}
                        disabled={marcadas.length === 0}
                        onClick={() => onConfirmar({ listas: marcadas, tipo, nome: nome.trim() || null, descricao: descricao.trim() || null })}
                    >
                        Montar rascunho
                    </Button>
                </div>
            </div>
        </div>
    );
}

function ReativarDialog({ lista, salvando, erro, onConfirmar, onFechar }) {
    const [justificativa, setJustificativa] = useState('');

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Tornar ativa">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Tornar “{lista.nome}” a lista final ativa?</h3>
                    <p className="text-sm text-on-surface-variant">
                        Ela passa a ser o resultado oficial da premiação — inclusive no Cerimonial —, no lugar da
                        final ativa de agora.
                    </p>
                </div>
                {erro && <Alert>{erro}</Alert>}
                <textarea
                    rows={3}
                    aria-label="Justificativa da troca"
                    placeholder="Por que esta lista volta a valer?"
                    value={justificativa}
                    onChange={(e) => setJustificativa(e.target.value)}
                    className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm"
                />
                <div className="flex justify-end gap-3">
                    <Button type="button" variant="outline" onClick={onFechar} disabled={salvando}>Cancelar</Button>
                    <Button type="button" loading={salvando} disabled={justificativa.trim().length < 5} onClick={() => onConfirmar(justificativa.trim())}>
                        Tornar ativa
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Ranking presencial → **Listas preliminares e finais** (Sprint 251).
 *
 * Como as listas da avaliação online, com uma diferença no que a final ativa
 * define: aqui ela é o **resultado oficial da premiação**, e não quem é
 * finalista. A lista nasce do ranking presencial (*Gerar lista*) ou da união
 * de listas já geradas, sempre como rascunho.
 */
export default function PresencialListas() {
    const navigate = useNavigate();
    const { user } = useAuth();
    const [modoTeste, setModoTeste] = useState(false);
    const [listas, setListas] = useState(null);
    const [erro, setErro] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [baixando, setBaixando] = useState(null);
    const [gerando, setGerando] = useState(false);
    const [montando, setMontando] = useState(false);
    const [reativando, setReativando] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [erroDialogo, setErroDialogo] = useState('');

    const carregar = useCallback(() => {
        getListasPresenciais(modoTeste)
            .then((l) => { setListas(l); setErro(''); })
            .catch(() => { setListas((l) => l ?? []); setErro('Não foi possível carregar as listas.'); });
    }, [modoTeste]);

    useEffect(() => { carregar(); }, [carregar]);

    async function baixar(id) {
        setBaixando(id); setErro('');
        try {
            await exportarListaPresencial(id, 'txt');
        } catch {
            setErro('Não foi possível baixar o arquivo.');
        } finally {
            setBaixando(null);
        }
    }

    async function montar(pedido) {
        setSalvando(true); setErroDialogo('');
        try {
            const d = await montarListaPresencialPorUniao(pedido, modoTeste);
            navigate(`/admin/presencial/listas/${d.lista.id}`);
        } catch (e) {
            setErroDialogo(extractErrors(e).message || 'Não foi possível montar a lista.');
        } finally {
            setSalvando(false);
        }
    }

    async function reativar(justificativa) {
        setSalvando(true); setErroDialogo('');
        try {
            const resp = await reativarListaPresencial(reativando.id, justificativa);
            setSucesso(resp.meta?.message ?? 'Lista reativada.');
            setReativando(null);
            carregar();
        } catch (e) {
            setErroDialogo(extractErrors(e).message || 'Não foi possível reativar.');
        } finally {
            setSalvando(false);
        }
    }

    const finais = (listas ?? []).filter((l) => l.tipo === 'final');
    const preliminares = (listas ?? []).filter((l) => l.tipo !== 'final');
    const geradas = (ls) => ls.filter((l) => !l.rascunho);

    const secao = (titulo, descricao, itens, vazio) => (
        <section className="mb-8 max-w-3xl">
            <h2 className="font-display text-lg font-semibold text-on-surface">{titulo}</h2>
            <p className="text-sm text-on-surface-variant mb-3">{descricao}</p>
            {itens.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">{vazio}</div>
            ) : (
                <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40">
                    {itens.map((l) => (
                        <LinhaLista key={l.id} lista={l} baixando={baixando} onBaixar={baixar} onReativar={(x) => { setErroDialogo(''); setReativando(x); }} />
                    ))}
                </ul>
            )}
        </section>
    );

    return (
        <AppShell>
            <Link to="/admin/presencial/ranking" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Ranking presencial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Listas da premiação</h1>
            <p className="text-sm text-on-surface-variant mb-4 max-w-3xl">
                Os premiados pela nota do estande. <strong>Preliminares</strong> podem existir várias; das
                <strong> finais</strong>, só uma é a <strong>ativa</strong> — o resultado oficial da premiação, que o
                Cerimonial lê. A lista final da avaliação online, que define os finalistas, não muda. Toda lista
                nasce <strong>rascunho</strong>.
            </p>

            {user?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle checked={modoTeste} onChange={setModoTeste} label="Modo de teste" description="Conta demo: trabalha sobre a lista final de demonstração." />
                </div>
            )}

            <div className="flex flex-wrap gap-2 mb-6">
                <Button type="button" onClick={() => setGerando(true)}>
                    <span className="material-symbols-outlined text-[20px]">leaderboard</span>
                    Gerar pela classificação
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={geradas(finais).length + geradas(preliminares).length === 0}
                    onClick={() => { setErroDialogo(''); setMontando(true); }}
                >
                    <span className="material-symbols-outlined text-[20px]">merge</span>
                    Montar a partir de outras listas
                </Button>
            </div>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            {listas === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    {secao(
                        'Listas finais',
                        'A ativa é o resultado oficial da premiação. Gerar outra final desativa a de agora; uma antiga volta a valer por “Tornar ativa”, com justificativa.',
                        finais,
                        'Nenhuma lista final ainda.',
                    )}
                    {secao(
                        'Listas preliminares',
                        'Recortes de trabalho: várias convivem, e as geradas podem ser juntadas numa nova lista.',
                        preliminares,
                        'Nenhuma lista preliminar ainda.',
                    )}
                </>
            )}

            <ListaPresencialDialog
                open={gerando}
                teste={modoTeste}
                onClose={(id) => {
                    setGerando(false);
                    if (id) navigate(`/admin/presencial/listas/${id}`);
                }}
            />
            {montando && (
                <MontarListaDialog
                    finais={geradas(finais)}
                    preliminares={geradas(preliminares)}
                    salvando={salvando}
                    erro={erroDialogo}
                    onConfirmar={montar}
                    onFechar={() => setMontando(false)}
                />
            )}
            {reativando && (
                <ReativarDialog lista={reativando} salvando={salvando} erro={erroDialogo} onConfirmar={reativar} onFechar={() => setReativando(null)} />
            )}
        </AppShell>
    );
}
