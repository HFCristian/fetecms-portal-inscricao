import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, Select, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getSuporte, pedirSuporte, alterarSuporte, excluirSuporte } from '../lib/suporte.js';

const VAZIO = {
    tipo: 'acompanhante', aluno_id: '', idioma: '',
    acompanhante_nome: '', acompanhante_documento: '', acompanhante_vinculo: '', observacao: '',
};

const COR = {
    pendente: 'bg-primary-fixed text-primary-container',
    aprovado: 'bg-secondary-container text-on-secondary-container',
    recusado: 'bg-error-container text-on-error-container',
};

const primeira = (v) => [].concat(v ?? [])[0];

/** O formulário de um pedido — novo ou alteração. */
function FormPedido({ projeto, inicial, tipos, salvando, erros, onSalvar, onCancelar }) {
    const [form, setForm] = useState(inicial ?? VAZIO);
    const campo = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
    const erro = (k) => primeira(erros?.[k]);

    return (
        <div className="mt-3 rounded-lg border border-outline-variant/50 p-3 space-y-3" aria-label={`Pedido de suporte de ${projeto.titulo}`}>
            <div className="grid sm:grid-cols-2 gap-3">
                <Field label="Tipo de suporte" error={erro('tipo')}>
                    <Select aria-label="Tipo de suporte" value={form.tipo} onChange={campo('tipo')}>
                        {tipos.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </Select>
                </Field>
                <Field label="Estudante" error={erro('aluno_id')} hint={form.tipo === 'acompanhante' ? 'Quem o acompanhante acompanha.' : 'Opcional.'}>
                    <Select aria-label="Estudante" value={form.aluno_id ?? ''} onChange={campo('aluno_id')}>
                        <option value="">—</option>
                        {projeto.alunos.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                    </Select>
                </Field>
                {form.tipo === 'interprete_lingua' && (
                    <Field label="Língua" error={erro('idioma')}>
                        <Input aria-label="Língua" placeholder="Espanhol, inglês…" value={form.idioma ?? ''} onChange={campo('idioma')} />
                    </Field>
                )}
                {form.tipo === 'acompanhante' && (
                    <>
                        <Field label="Nome completo do acompanhante" error={erro('acompanhante_nome')}>
                            <Input aria-label="Nome completo do acompanhante" value={form.acompanhante_nome ?? ''} onChange={campo('acompanhante_nome')} />
                        </Field>
                        <Field label="Documento" error={erro('acompanhante_documento')} hint="RG ou CPF, com o número — vai no crachá e é conferido na entrada.">
                            <Input aria-label="Documento do acompanhante" value={form.acompanhante_documento ?? ''} onChange={campo('acompanhante_documento')} />
                        </Field>
                        <Field label="Vínculo com o estudante" error={erro('acompanhante_vinculo')} hint="Mãe, pai, responsável legal, profissional de apoio…">
                            <Input aria-label="Vínculo com o estudante" value={form.acompanhante_vinculo ?? ''} onChange={campo('acompanhante_vinculo')} />
                        </Field>
                    </>
                )}
            </div>
            <Field label="Observação" error={erro('observacao')} hint="Opcional: o que a organização precisa saber.">
                <textarea
                    rows={2}
                    maxLength={1000}
                    aria-label="Observação"
                    value={form.observacao ?? ''}
                    onChange={campo('observacao')}
                    className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm"
                />
            </Field>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onCancelar} disabled={salvando}>Cancelar</Button>
                <Button type="button" loading={salvando} onClick={() => onSalvar({ ...form, aluno_id: form.aluno_id || null })}>
                    {inicial ? 'Salvar alteração' : 'Enviar pedido'}
                </Button>
            </div>
        </div>
    );
}

/**
 * Aba **Suporte no evento** do orientador (Sprint 162).
 *
 * Para cada projeto seu que está na lista final, o orientador pede
 * **acompanhante** (para estudante neurodivergente ou com deficiência) ou
 * **intérprete** — de Libras ou de outra língua. O acompanhante é cadastrado
 * aqui (nome completo, documento e vínculo com o estudante) porque entra no
 * evento: recebe crachá e passa pelo controle de acesso.
 *
 * A organização aprova ou recusa; enquanto não decide, o pedido fica
 * "aguardando". Alterar um pedido já aprovado o devolve para a análise.
 */
export default function SuporteEvento() {
    const [dados, setDados] = useState(null);
    const [modoTeste, setModoTeste] = useState(false);
    const [editando, setEditando] = useState(null); // { projetoId, suporte? }
    const [salvando, setSalvando] = useState(false);
    const [erros, setErros] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');

    const carregar = useCallback((teste) => getSuporte(teste)
        .then(setDados)
        .catch(() => setDados({ janela: { aberta: false, tipos: [] }, projetos: [] })), []);

    useEffect(() => { carregar(modoTeste); }, [carregar, modoTeste]);

    async function salvar(projeto, payload) {
        setSalvando(true); setErros({}); setAlerta('');
        try {
            const resp = editando?.suporte
                ? await alterarSuporte(editando.suporte.id, payload, modoTeste)
                : await pedirSuporte(projeto.id, payload, modoTeste);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? 'Pedido salvo.');
            setEditando(null);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErros(fields ?? {});
            setAlerta(primeira(fields?.periodo) || primeira(fields?.projeto) || message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    async function excluir(suporte) {
        setSalvando(true); setAlerta('');
        try {
            const resp = await excluirSuporte(suporte.id, modoTeste);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? 'Pedido excluído.');
        } catch (e) {
            setAlerta(extractErrors(e).message || 'Não foi possível excluir.');
        } finally {
            setSalvando(false);
        }
    }

    const janela = dados?.janela;

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Suporte no evento</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Se algum estudante dos seus projetos finalistas precisa de <strong>acompanhante</strong>{' '}
                (estudante neurodivergente ou com deficiência) ou de <strong>intérprete</strong> — de Libras
                ou de outra língua —, peça aqui. O acompanhante recebe crachá: informe o nome completo, o
                documento e o vínculo com o estudante. A organização analisa cada pedido.
            </p>

            {janela?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle checked={modoTeste} onChange={setModoTeste} label="Modo de teste" description="Conta demo: usa a lista final de demonstração e ignora as datas do evento." />
                </div>
            )}

            <div className="max-w-3xl space-y-3">
                <Alert>{alerta}</Alert>
                <Alert type="info">{sucesso}</Alert>
            </div>

            {dados === null ? (
                <div className="text-center py-10">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin" role="status" aria-label="Carregando" />
                </div>
            ) : !janela.tem_lista ? (
                <div className="max-w-3xl bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-sm text-on-surface-variant">
                    A lista final ainda não saiu. Quando a organização publicar os finalistas, seus projetos selecionados aparecem aqui.
                </div>
            ) : dados.projetos.length === 0 ? (
                <div className="max-w-3xl bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-sm text-on-surface-variant">
                    Nenhum dos seus projetos está na lista final desta edição.
                </div>
            ) : (
                <ul className="max-w-3xl space-y-3 mt-3">
                    {!janela.aberta && (
                        <Alert type="info">O evento terminou em {janela.evento_ate_label}: os pedidos ficam só para consulta.</Alert>
                    )}
                    {dados.projetos.map((p) => (
                        <li key={p.id} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-semibold text-on-surface">{p.titulo}</p>
                                    <p className="text-xs text-on-surface-variant">{[p.area, p.categoria].filter(Boolean).join(' · ')}</p>
                                </div>
                                {janela.aberta && editando?.projetoId !== p.id && (
                                    <Button type="button" variant="outline" onClick={() => { setEditando({ projetoId: p.id }); setErros({}); }}>
                                        <span className="material-symbols-outlined text-[20px]">add</span>
                                        Pedir suporte
                                    </Button>
                                )}
                            </div>

                            {p.suportes.length > 0 && (
                                <ul className="mt-3 space-y-2">
                                    {p.suportes.map((s) => (
                                        <li key={s.id} className="rounded-lg border border-outline-variant/40 p-3 text-sm">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${COR[s.status]}`}>{s.status_label}</span>
                                                <span className="font-semibold">{s.resumo}</span>
                                            </div>
                                            {s.acompanhante_documento && <p className="text-xs text-on-surface-variant">Documento: {s.acompanhante_documento}</p>}
                                            {s.status === 'recusado' && s.motivo && <p className="text-xs text-error">Motivo da organização: {s.motivo}</p>}
                                            {janela.aberta && (
                                                <div className="flex gap-2 mt-2">
                                                    <Button type="button" variant="outline" disabled={salvando} onClick={() => { setEditando({ projetoId: p.id, suporte: s }); setErros({}); }}>
                                                        Alterar
                                                    </Button>
                                                    <Button type="button" variant="outline" className="text-error border-error/40" disabled={salvando} onClick={() => excluir(s)}>
                                                        Excluir
                                                    </Button>
                                                </div>
                                            )}
                                            {editando?.suporte?.id === s.id && (
                                                <FormPedido
                                                    projeto={p}
                                                    inicial={{ ...s, aluno_id: s.aluno_id ?? '' }}
                                                    tipos={janela.tipos}
                                                    salvando={salvando}
                                                    erros={erros}
                                                    onSalvar={(payload) => salvar(p, payload)}
                                                    onCancelar={() => setEditando(null)}
                                                />
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {editando?.projetoId === p.id && !editando.suporte && (
                                <FormPedido
                                    projeto={p}
                                    tipos={janela.tipos}
                                    salvando={salvando}
                                    erros={erros}
                                    onSalvar={(payload) => salvar(p, payload)}
                                    onCancelar={() => setEditando(null)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
