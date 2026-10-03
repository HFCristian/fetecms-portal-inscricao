import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getListasFinais, baixarListaOficial, criarFinalDePreliminares, reativarListaFinal } from '../lib/admin.js';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

const pill = 'ml-2 align-middle text-xs font-semibold px-2 py-0.5 rounded-full';

/** Uma lista na relação: nome, situação, números e as ações. */
function LinhaLista({ lista, baixando, onBaixar, onReativar }) {
    return (
        <li className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-on-surface">
                    {lista.nome}
                    {lista.vigente && <span className={`${pill} bg-secondary-container text-on-secondary-container`}>ativa</span>}
                    {lista.rascunho && <span className={`${pill} bg-surface-variant text-on-surface-variant`}>rascunho</span>}
                    {lista.tipo === 'final' && !lista.rascunho && !lista.vigente && (
                        <span className={`${pill} bg-surface-variant text-on-surface-variant`}>inativa</span>
                    )}
                </p>
                <p className="text-xs text-on-surface-variant">
                    versão {lista.versao} · {lista.projetos} {lista.projetos === 1 ? 'projeto' : 'projetos'}
                    {lista.gerada_por ? ` · por ${lista.gerada_por}` : ''}
                    {lista.gerada_em ? ` · gerada em ${dataHora(lista.gerada_em)}` : ` · criada em ${dataHora(lista.criada_em)}`}
                </p>
                {lista.origens?.length > 0 && (
                    <p className="text-xs text-on-surface-variant">
                        Montada das preliminares: {lista.origens.map((o) => o.nome).join(', ')}
                    </p>
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                <Link
                    to={`/admin/avaliacao/listas-finais/${lista.id}`}
                    className="inline-flex items-center gap-2 rounded-lg border border-outline-variant px-4 py-2.5 text-sm font-semibold text-on-surface hover:bg-surface-variant transition-colors"
                >
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

/** Escolher as preliminares que formam a nova lista final. */
function MontarFinalDialog({ preliminares, salvando, erro, onConfirmar, onFechar }) {
    const [marcadas, setMarcadas] = useState([]);
    const [nome, setNome] = useState('');
    const alternar = (id) => setMarcadas((m) => (m.includes(id) ? m.filter((x) => x !== id) : [...m, id]));

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Montar lista final de preliminares</h3>
                    <p className="text-sm text-on-surface-variant">
                        A final reúne todos os projetos das preliminares marcadas, sem repetir. Ela abre como
                        rascunho: dá para incluir e retirar antes de gerar.
                    </p>
                </div>
                {erro && <Alert>{erro}</Alert>}
                <ul className="space-y-1">
                    {preliminares.map((p) => (
                        <li key={p.id}>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={marcadas.includes(p.id)} onChange={() => alternar(p.id)} aria-label={p.nome} />
                                <span>{p.nome} <span className="text-on-surface-variant">· {p.projetos} projeto(s)</span></span>
                            </label>
                        </li>
                    ))}
                </ul>
                <input
                    value={nome}
                    onChange={(e) => setNome(e.target.value)}
                    placeholder="Nome da lista final (opcional)"
                    aria-label="Nome da lista final"
                    maxLength={120}
                    className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm"
                />
                <div className="flex justify-end gap-3">
                    <Button type="button" variant="outline" onClick={onFechar} disabled={salvando}>Cancelar</Button>
                    <Button type="button" loading={salvando} disabled={marcadas.length === 0} onClick={() => onConfirmar(marcadas, nome.trim() || null)}>
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
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Tornar “{lista.nome}” a lista final ativa?</h3>
                    <p className="text-sm text-on-surface-variant">
                        Os projetos dela passam a ser os finalistas de toda a etapa presencial — credenciamento,
                        mapa, crachás e avaliação no estande —, no lugar da final ativa de agora.
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
 * Avaliação online → **Listas preliminares e finais** (Sprint 164).
 *
 * **Preliminares** são recortes de trabalho: várias convivem, e nenhuma define
 * finalista. **Finais** têm uma só **ativa**, que vale para toda a etapa
 * presencial; gerar uma nova desativa a anterior, que fica no histórico e pode
 * voltar com justificativa. A final nasce da classificação (Ranking → *Gerar
 * lista*) ou da **união de preliminares** escolhidas aqui. Toda lista nasce
 * **rascunho**: o admin edita à vontade e só então a gera.
 */
export default function AvaliacaoListasFinais() {
    const navigate = useNavigate();
    const [listas, setListas] = useState(null);
    const [erro, setErro] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [baixando, setBaixando] = useState(null);
    const [montando, setMontando] = useState(false);
    const [reativando, setReativando] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [erroDialogo, setErroDialogo] = useState('');

    const carregar = useCallback(() => {
        getListasFinais()
            .then((l) => { setListas(l); setErro(''); })
            .catch(() => setErro('Não foi possível carregar as listas.'));
    }, []);

    useEffect(() => { carregar(); }, [carregar]);

    async function baixar(id) {
        setBaixando(id);
        setErro('');
        try {
            await baixarListaOficial(id);
        } catch {
            setErro('Não foi possível baixar o arquivo.');
        } finally {
            setBaixando(null);
        }
    }

    async function montar(ids, nome) {
        setSalvando(true); setErroDialogo('');
        try {
            const d = await criarFinalDePreliminares(ids, nome);
            navigate(`/admin/avaliacao/listas-finais/${d.lista.id}`);
        } catch (e) {
            setErroDialogo(extractErrors(e).message || 'Não foi possível montar a lista.');
        } finally {
            setSalvando(false);
        }
    }

    async function reativar(justificativa) {
        setSalvando(true); setErroDialogo('');
        try {
            const resp = await reativarListaFinal(reativando.id, justificativa);
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
    const preliminaresGeradas = preliminares.filter((l) => !l.rascunho);

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
            <Link to="/admin/avaliacao/ranking" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Ranking dos projetos
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Listas preliminares e finais</h1>
            <p className="text-sm text-on-surface-variant mb-4 max-w-3xl">
                <strong>Preliminares</strong> podem existir várias, e nenhuma define finalista. Das
                <strong> finais</strong>, só uma é a <strong>ativa</strong>: é ela que vale para o credenciamento,
                o mapa, os crachás e a avaliação presencial. Toda lista nasce <strong>rascunho</strong> — você
                edita à vontade e depois a gera.
            </p>

            <div className="flex flex-wrap gap-2 mb-6">
                <Link
                    to="/admin/avaliacao/ranking"
                    className="inline-flex items-center gap-2 rounded-lg border border-outline-variant px-4 py-2.5 text-sm font-semibold text-on-surface hover:bg-surface-variant transition-colors"
                >
                    <span className="material-symbols-outlined text-[20px]">leaderboard</span>
                    Gerar pela classificação
                </Link>
                <Button type="button" variant="outline" disabled={preliminaresGeradas.length === 0} onClick={() => { setErroDialogo(''); setMontando(true); }}>
                    <span className="material-symbols-outlined text-[20px]">merge</span>
                    Lista final a partir de preliminares
                </Button>
                <Link
                    to="/admin/avaliacao/projetos-manuais"
                    className="inline-flex items-center gap-2 rounded-lg border border-outline-variant px-4 py-2.5 text-sm font-semibold text-on-surface hover:bg-surface-variant transition-colors"
                >
                    <span className="material-symbols-outlined text-[20px]">post_add</span>
                    Cadastro manual de projetos
                </Link>
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
                        'A ativa define os finalistas. Gerar outra final desativa a de agora; uma final antiga volta a valer por “Tornar ativa”, com justificativa.',
                        finais,
                        'Nenhuma lista final ainda.',
                    )}
                    {secao(
                        'Listas preliminares',
                        'Recortes de trabalho: várias convivem, e as geradas podem ser juntadas numa lista final.',
                        preliminares,
                        'Nenhuma lista preliminar ainda. Gere uma no Ranking dos projetos, em “Gerar lista”.',
                    )}
                </>
            )}

            {montando && (
                <MontarFinalDialog preliminares={preliminaresGeradas} salvando={salvando} erro={erroDialogo} onConfirmar={montar} onFechar={() => setMontando(false)} />
            )}
            {reativando && (
                <ReativarDialog lista={reativando} salvando={salvando} erro={erroDialogo} onConfirmar={reativar} onFechar={() => setReativando(null)} />
            )}
        </AppShell>
    );
}
