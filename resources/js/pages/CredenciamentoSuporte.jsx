import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, Select } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getSuportesAdmin, criarSuporteAdmin, decidirSuporte, excluirSuporteAdmin, getFinalistas, getFichaCredenciamento,
} from '../lib/credenciamento.js';

const COR = {
    pendente: 'bg-primary-fixed text-primary-container',
    aprovado: 'bg-secondary-container text-on-secondary-container',
    recusado: 'bg-error-container text-on-error-container',
};

const NOVO = {
    projeto: null, alunos: [], tipo: 'acompanhante', aluno_id: '', idioma: '',
    acompanhante_nome: '', acompanhante_documento: '', acompanhante_vinculo: '', observacao: '',
};

/**
 * Credenciamento → **Suporte e acessibilidade** (Sprint 162).
 *
 * Os pedidos de acompanhante (estudante neurodivergente ou com deficiência) e
 * de intérprete — de Libras ou de outra língua — que os orientadores fazem na
 * aba *Suporte* deles. A organização **aprova ou recusa** (recusar pede motivo,
 * que o orientador lê). Só o aprovado aparece para as equipes de credenciamento
 * e de avaliação, e o acompanhante aprovado ganha crachá.
 *
 * Pedido que chegou por fora (e-mail, telefone) é registrado aqui mesmo, já
 * aprovado.
 */
export default function CredenciamentoSuporte() {
    const [dados, setDados] = useState(null);
    const [filtros, setFiltros] = useState({ status: '', tipo: '', q: '' });
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [ocupado, setOcupado] = useState(false);
    const [recusando, setRecusando] = useState(null);
    const [motivo, setMotivo] = useState('');
    const [novo, setNovo] = useState(null);
    const [finalistas, setFinalistas] = useState([]);
    const [buscaProjeto, setBuscaProjeto] = useState('');
    const [errosNovo, setErrosNovo] = useState({});

    const carregar = useCallback(() => {
        getSuportesAdmin(filtros).then(setDados).catch(() => setAlerta('Não foi possível carregar os pedidos.'));
    }, [filtros]);

    useEffect(() => {
        const t = setTimeout(carregar, 250);
        return () => clearTimeout(t);
    }, [carregar]);

    function aplicar(resp) {
        setDados(resp.data);
        setSucesso(resp.meta?.message ?? '');
        setAlerta('');
    }

    async function agir(fn) {
        setOcupado(true);
        try {
            aplicar(await fn());
            return true;
        } catch (e) {
            setAlerta(extractErrors(e).message || 'Não foi possível concluir.');
            return false;
        } finally {
            setOcupado(false);
        }
    }

    function abrirNovo() {
        setNovo({ ...NOVO });
        setErrosNovo({});
        setBuscaProjeto('');
    }

    // A lista de finalistas é grande: quem filtra é o servidor.
    useEffect(() => {
        if (!novo || novo.projeto) return undefined;
        const t = setTimeout(() => {
            getFinalistas({ busca: buscaProjeto.trim() || undefined, por_pagina: 20 })
                .then((r) => setFinalistas((r.data ?? []).map((p) => ({ id: p.id, nome: p.titulo, detalhe: [p.area, p.escola].filter(Boolean).join(' · ') }))))
                .catch(() => setFinalistas([]));
        }, 300);
        return () => clearTimeout(t);
    }, [buscaProjeto, novo]);

    async function escolherProjeto(projeto) {
        setNovo((n) => ({ ...n, projeto, alunos: [], aluno_id: '' }));
        if (!projeto) return;
        try {
            const ficha = await getFichaCredenciamento(projeto.id);
            setNovo((n) => ({ ...n, alunos: ficha.pessoas.filter((p) => p.tipo === 'aluno') }));
        } catch {
            // Sem a ficha, o pedido ainda pode ser registrado sem estudante.
        }
    }

    async function registrar() {
        setErrosNovo({});
        setOcupado(true);
        try {
            const { projeto, alunos, ...campos } = novo;
            aplicar(await criarSuporteAdmin(projeto.id, { ...campos, aluno_id: campos.aluno_id || null, aprovar: true }));
            setNovo(null);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErrosNovo(fields ?? {});
            setAlerta(message || 'Não foi possível registrar.');
        } finally {
            setOcupado(false);
        }
    }

    const campoNovo = (k) => (e) => setNovo((n) => ({ ...n, [k]: e.target.value }));
    const erroNovo = (k) => [].concat(errosNovo[k] ?? [])[0];

    return (
        <AppShell>
            <Link to="/admin/credenciamento" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Credenciamento
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Suporte e acessibilidade</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                Pedidos de <strong>acompanhante</strong> e de <strong>intérprete</strong> (Libras ou outra língua)
                feitos pelos orientadores. Só o que for <strong>aprovado</strong> aparece para as equipes de
                credenciamento e de avaliação — e o acompanhante aprovado ganha crachá.
            </p>

            {alerta && <div className="mb-4 max-w-4xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-4xl"><Alert type="info">{sucesso}</Alert></div>}

            {dados && (
                <div className="flex flex-wrap gap-2 mb-4 text-sm">
                    <span className={`px-3 py-1 rounded-full font-semibold ${COR.pendente}`}>{dados.totais.pendente} aguardando</span>
                    <span className={`px-3 py-1 rounded-full font-semibold ${COR.aprovado}`}>{dados.totais.aprovado} aprovados</span>
                    <span className={`px-3 py-1 rounded-full font-semibold ${COR.recusado}`}>{dados.totais.recusado} recusados</span>
                </div>
            )}

            <div className="flex flex-wrap items-end gap-3 mb-4 max-w-4xl">
                <Field label="Buscar">
                    <Input aria-label="Buscar pedido" placeholder="Projeto, orientador, acompanhante…" value={filtros.q} onChange={(e) => setFiltros((f) => ({ ...f, q: e.target.value }))} />
                </Field>
                <Field label="Situação">
                    <Select aria-label="Situação" value={filtros.status} onChange={(e) => setFiltros((f) => ({ ...f, status: e.target.value }))}>
                        <option value="">Todas</option>
                        <option value="pendente">Aguardando</option>
                        <option value="aprovado">Aprovados</option>
                        <option value="recusado">Recusados</option>
                    </Select>
                </Field>
                <Field label="Tipo">
                    <Select aria-label="Tipo" value={filtros.tipo} onChange={(e) => setFiltros((f) => ({ ...f, tipo: e.target.value }))}>
                        <option value="">Todos</option>
                        {(dados?.tipos ?? []).map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </Select>
                </Field>
                {!novo && (
                    <Button type="button" variant="outline" onClick={abrirNovo}>
                        <span className="material-symbols-outlined text-[20px]">add</span>
                        Registrar pedido recebido por fora
                    </Button>
                )}
            </div>

            {novo && (
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-6 max-w-4xl space-y-3" aria-label="Novo pedido">
                    <h2 className="font-display text-primary font-semibold">Registrar pedido (já aprovado)</h2>
                    {novo.projeto ? (
                        <p className="text-sm">
                            Projeto: <strong>{novo.projeto.nome}</strong>{' '}
                            <button type="button" className="text-primary underline" onClick={() => escolherProjeto(null)}>trocar</button>
                        </p>
                    ) : (
                        <div>
                            <Field label="Projeto finalista">
                                <Input aria-label="Buscar projeto finalista" placeholder="Título, orientador ou escola…" value={buscaProjeto} onChange={(e) => setBuscaProjeto(e.target.value)} />
                            </Field>
                            <ul className="max-h-48 overflow-y-auto border border-outline-variant/40 rounded-lg divide-y divide-outline-variant/30 mt-1">
                                {finalistas.map((f) => (
                                    <li key={f.id}>
                                        <button type="button" className="w-full text-left px-3 py-2 text-sm hover:bg-surface-variant" onClick={() => escolherProjeto(f)}>
                                            {f.nome} <span className="text-on-surface-variant">· {f.detalhe}</span>
                                        </button>
                                    </li>
                                ))}
                                {finalistas.length === 0 && <li className="px-3 py-2 text-sm text-on-surface-variant">Nenhum finalista encontrado.</li>}
                            </ul>
                        </div>
                    )}
                    <div className="grid sm:grid-cols-2 gap-3">
                        <Field label="Tipo" error={erroNovo('tipo')}>
                            <Select aria-label="Tipo do pedido" value={novo.tipo} onChange={campoNovo('tipo')}>
                                {(dados?.tipos ?? []).map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                            </Select>
                        </Field>
                        <Field label="Estudante" error={erroNovo('aluno_id')}>
                            <Select aria-label="Estudante" value={novo.aluno_id} onChange={campoNovo('aluno_id')}>
                                <option value="">—</option>
                                {novo.alunos.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                            </Select>
                        </Field>
                        {novo.tipo === 'interprete_lingua' && (
                            <Field label="Língua" error={erroNovo('idioma')}>
                                <Input aria-label="Língua" value={novo.idioma} onChange={campoNovo('idioma')} />
                            </Field>
                        )}
                        {novo.tipo === 'acompanhante' && (
                            <>
                                <Field label="Nome completo do acompanhante" error={erroNovo('acompanhante_nome')}>
                                    <Input aria-label="Nome completo do acompanhante" value={novo.acompanhante_nome} onChange={campoNovo('acompanhante_nome')} />
                                </Field>
                                <Field label="Documento" error={erroNovo('acompanhante_documento')} hint="RG ou CPF, com o número.">
                                    <Input aria-label="Documento do acompanhante" value={novo.acompanhante_documento} onChange={campoNovo('acompanhante_documento')} />
                                </Field>
                                <Field label="Vínculo com o estudante" error={erroNovo('acompanhante_vinculo')} hint="Mãe, pai, responsável, profissional de apoio…">
                                    <Input aria-label="Vínculo com o estudante" value={novo.acompanhante_vinculo} onChange={campoNovo('acompanhante_vinculo')} />
                                </Field>
                            </>
                        )}
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => setNovo(null)}>Cancelar</Button>
                        <Button type="button" loading={ocupado} disabled={!novo.projeto} onClick={registrar}>Registrar</Button>
                    </div>
                </section>
            )}

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow max-w-4xl overflow-hidden">
                {dados === null ? (
                    <div className="text-center py-10">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin" role="status" aria-label="Carregando" />
                    </div>
                ) : dados.pedidos.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-on-surface-variant">Nenhum pedido neste recorte.</p>
                ) : (
                    <ul className="divide-y divide-outline-variant/40">
                        {dados.pedidos.map((p) => (
                            <li key={p.id} className="p-4 space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${COR[p.status]}`}>{p.status_label}</span>
                                    <span className="font-semibold text-on-surface">{p.resumo}</span>
                                </div>
                                <p className="text-xs text-on-surface-variant">
                                    {p.projeto} · orientador {p.orientador}
                                    {p.acompanhante_documento ? ` · documento ${p.acompanhante_documento}` : ''}
                                    {p.criado_em ? ` · pedido em ${p.criado_em}` : ''}
                                    {p.decidido_por ? ` · decidido por ${p.decidido_por} em ${p.decidido_em}` : ''}
                                </p>
                                {p.observacao && <p className="text-xs text-on-surface">Obs.: {p.observacao}</p>}
                                {p.motivo && <p className="text-xs text-error">Motivo: {p.motivo}</p>}
                                <div className="flex flex-wrap gap-2 pt-1">
                                    {p.status !== 'aprovado' && (
                                        <Button type="button" disabled={ocupado} onClick={() => agir(() => decidirSuporte(p.id, true))}>
                                            <span className="material-symbols-outlined text-[20px]">check</span>
                                            Aprovar
                                        </Button>
                                    )}
                                    {p.status !== 'recusado' && (
                                        <Button type="button" variant="outline" disabled={ocupado} onClick={() => { setRecusando(p); setMotivo(''); }}>
                                            Recusar
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="text-error border-error/40"
                                        disabled={ocupado}
                                        onClick={() => agir(() => excluirSuporteAdmin(p.id))}
                                    >
                                        Excluir
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {recusando && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                    <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                        <h3 className="font-display text-lg font-semibold text-on-surface">Recusar o pedido</h3>
                        <p className="text-sm text-on-surface-variant">{recusando.resumo}. O orientador lê o motivo na aba Suporte.</p>
                        <textarea
                            rows={3}
                            aria-label="Motivo da recusa"
                            value={motivo}
                            onChange={(e) => setMotivo(e.target.value)}
                            className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm"
                        />
                        <div className="flex justify-end gap-3">
                            <Button type="button" variant="outline" onClick={() => setRecusando(null)} disabled={ocupado}>Cancelar</Button>
                            <Button
                                type="button"
                                loading={ocupado}
                                disabled={motivo.trim() === ''}
                                onClick={async () => { if (await agir(() => decidirSuporte(recusando.id, false, motivo.trim()))) setRecusando(null); }}
                            >
                                Recusar
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    );
}
