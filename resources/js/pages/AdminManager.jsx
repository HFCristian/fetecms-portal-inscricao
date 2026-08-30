import { useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Field, Input, Button, Alert, useConfirm } from '../components/ui.jsx';
import { extractErrors, useAuth } from '../lib/auth.jsx';
import { criarAdmin, getAdmins, atualizarAdmin, definirStatusAdmin, definirEscoposAdmin, definirDemoAdmin } from '../lib/admin.js';

function CriarAdminForm({ onCriado }) {
    const [form, setForm] = useState({});
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const [loading, setLoading] = useState(false);
    const set = (n) => (e) => setForm((f) => ({ ...f, [n]: e.target.value }));
    const err = (n) => errors[n]?.[0];

    async function onSubmit(e) {
        e.preventDefault();
        setAlert(''); setSuccess(''); setErrors({}); setLoading(true);
        try {
            const novo = await criarAdmin({ ...form, password_confirmation: form.password_confirmation ?? '' });
            setSuccess(`Administrador ${novo.name} criado.`);
            setForm({});
            onCriado?.();
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors(fields); setAlert(message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <form onSubmit={onSubmit} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 space-y-4">
            <h2 className="font-display text-primary font-semibold">Criar administrador</h2>
            <p className="text-sm text-on-surface-variant">Apenas administradores podem criar novos administradores.</p>
            {alert && <Alert>{alert}</Alert>}
            {success && <Alert type="info">{success}</Alert>}
            <Field label="Nome" required error={err('name')}>
                <Input value={form.name ?? ''} onChange={set('name')} error={err('name')} />
            </Field>
            <Field label="E-mail" required error={err('email')}>
                <Input type="email" value={form.email ?? ''} onChange={set('email')} error={err('email')} />
            </Field>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field label="Senha" required error={err('password')} hint="Mínimo de 8 caracteres.">
                    <Input type="password" value={form.password ?? ''} onChange={set('password')} error={err('password')} />
                </Field>
                <Field label="Confirmar Senha" required>
                    <Input type="password" value={form.password_confirmation ?? ''} onChange={set('password_confirmation')} />
                </Field>
            </div>
            <Button type="submit" loading={loading}>
                <span className="material-symbols-outlined text-[20px]">person_add</span>
                CRIAR ADMINISTRADOR
            </Button>
        </form>
    );
}

function StatusPill({ ativo }) {
    return (
        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ${
            ativo ? 'bg-secondary text-on-secondary' : 'bg-surface-variant text-on-surface-variant'
        }`}>
            {ativo ? 'Ativo' : 'Inativo'}
        </span>
    );
}

/**
 * Os escopos de uma pessoa NA EDIÇÃO EM CURSO — os *roles* do RBAC. Ela pode ter
 * quantos quiser, e o que abre é a união das abas de todos. Nenhum marcado =
 * acesso total, que é o comportamento de quem nunca foi configurado.
 */
function EscoposDoAdmin({ admin, escopos, selecionados, onAlternar }) {
    const total = selecionados.length === 0;

    return (
        <div className="mt-1.5">
            <div className="flex items-center gap-2 flex-wrap">
                <span className="text-xs text-on-surface-variant">Escopos:</span>
                {total && (
                    <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-primary-fixed text-primary-container">
                        Acesso total
                    </span>
                )}
            </div>
            <div role="group" aria-label={`Escopos de ${admin.name}`} className="flex gap-1.5 flex-wrap mt-1">
                {escopos.map((e) => {
                    const marcado = selecionados.includes(e.id);
                    return (
                        <button
                            key={e.id}
                            type="button"
                            aria-pressed={marcado}
                            onClick={() => onAlternar(e.id)}
                            className={`text-xs px-2 py-0.5 rounded-full border transition-colors ${
                                marcado
                                    ? 'bg-primary-container text-on-primary border-primary-container'
                                    : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:border-primary-container'
                            }`}
                        >
                            {e.nome}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function LinhaAdmin({ admin, souEu, editando, form, setForm, err, salvando, escopos, escopoIds, onEscopos, onEditar, onCancelar, onSalvar, onStatus, onDemo }) {
    if (editando) {
        return (
            <div className="px-4 py-3 border-b border-outline-variant/30 last:border-0 space-y-3">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <Field label="Nome" error={err('name')}>
                        <Input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} error={err('name')} />
                    </Field>
                    <Field label="E-mail" error={err('email')}>
                        <Input type="email" value={form.email} onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} error={err('email')} />
                    </Field>
                </div>
                <div className="flex gap-2 justify-end">
                    <Button type="button" variant="outline" onClick={onCancelar}>Cancelar</Button>
                    <Button type="button" loading={salvando} onClick={onSalvar}>Salvar</Button>
                </div>
            </div>
        );
    }

    return (
        <div className="px-4 py-3 border-b border-outline-variant/30 last:border-0 flex items-center gap-3 flex-wrap">
            <span className="material-symbols-outlined text-primary-container">account_circle</span>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-semibold text-on-surface truncate">{admin.name}</span>
                    <StatusPill ativo={admin.is_active} />
                    {admin.is_demo && (
                        <span className="text-xs font-semibold px-2 py-0.5 rounded-full whitespace-nowrap bg-primary-fixed text-primary-container">
                            Modo demo
                        </span>
                    )}
                    {souEu && <span className="text-xs text-on-surface-variant">(você)</span>}
                </div>
                <p className="text-sm text-on-surface-variant truncate">{admin.email}</p>
                {escopos !== null && escopos.length > 0 && (
                    <EscoposDoAdmin
                        admin={admin}
                        escopos={escopos}
                        selecionados={escopoIds}
                        onAlternar={(id) => onEscopos(
                            escopoIds.includes(id) ? escopoIds.filter((x) => x !== id) : [...escopoIds, id],
                        )}
                    />
                )}
            </div>
            <div className="flex gap-1 shrink-0">
                {/* Modo demo: libera para esta pessoa as funcionalidades que
                    dependem de data (credenciar antes do evento, por exemplo)
                    para ela treinar antes do prazo. */}
                <button
                    type="button"
                    onClick={onDemo}
                    aria-pressed={!!admin.is_demo}
                    title={admin.is_demo
                        ? `Desativar o modo demo de ${admin.name}`
                        : `Liberar o modo demo para ${admin.name}`}
                    className={`p-1.5 rounded-lg transition-colors ${
                        admin.is_demo
                            ? 'text-primary-container bg-primary-fixed hover:bg-primary-fixed/70'
                            : 'text-on-surface-variant hover:bg-surface-variant'
                    }`}
                >
                    <span className="material-symbols-outlined text-[20px]">science</span>
                </button>
                <button type="button" onClick={onEditar} title="Editar"
                    className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-variant transition-colors">
                    <span className="material-symbols-outlined text-[20px]">edit</span>
                </button>
                {admin.is_active ? (
                    <button type="button" onClick={onStatus} disabled={souEu}
                        title={souEu ? 'Você não pode desativar a si mesmo' : 'Desativar'}
                        className="p-1.5 rounded-lg text-error hover:bg-error-container disabled:opacity-30 disabled:cursor-not-allowed transition-colors">
                        <span className="material-symbols-outlined text-[20px]">block</span>
                    </button>
                ) : (
                    <button type="button" onClick={onStatus} title="Reativar"
                        className="p-1.5 rounded-lg text-secondary hover:bg-surface-variant transition-colors">
                        <span className="material-symbols-outlined text-[20px]">how_to_reg</span>
                    </button>
                )}
            </div>
        </div>
    );
}

function AdminList() {
    const { user } = useAuth();
    const [admins, setAdmins] = useState(null);
    // Escopos disponíveis e o de cada admin na edição em curso (Sprint 67);
    // ambos chegam junto da lista de administradores.
    const [escopos, setEscopos] = useState(null);
    const [escopoPorAdmin, setEscopoPorAdmin] = useState({});
    const [editId, setEditId] = useState(null);
    const [form, setForm] = useState({ name: '', email: '' });
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [confirm, dialogo] = useConfirm();
    const err = (n) => errors[n]?.[0];

    function carregar() {
        return getAdmins()
            .then((r) => {
                setAdmins(r.data ?? []);
                setEscopos(r.meta?.escopos ?? null);
                setEscopoPorAdmin(r.meta?.escopo_por_admin ?? {});
            })
            .catch(() => setAdmins([]));
    }
    useEffect(() => { carregar(); }, []);


    async function trocarEscopos(adminId, escopoIds) {
        setAlert('');
        try {
            setEscopoPorAdmin(await definirEscoposAdmin(adminId, escopoIds));
        } catch (e) {
            setAlert(extractErrors(e).message || 'Não foi possível trocar os escopos.');
        }
    }

    async function alternarDemo(a) {
        setAlert('');
        try {
            const { data } = await definirDemoAdmin(a.id, !a.is_demo);
            // Troca só a linha alterada: recarregar tudo perderia a edição aberta.
            setAdmins((lista) => lista.map((x) => (x.id === a.id ? { ...x, is_demo: data.is_demo } : x)));
        } catch (e) {
            setAlert(extractErrors(e).message || 'Não foi possível mudar o modo demo.');
        }
    }

    function abrirEdicao(a) {
        setEditId(a.id); setForm({ name: a.name, email: a.email }); setErrors({}); setAlert('');
    }

    async function salvar(id) {
        setSalvando(true); setErrors({}); setAlert('');
        try {
            await atualizarAdmin(id, form);
            setEditId(null);
            await carregar();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErrors(fields); setAlert(message);
        } finally {
            setSalvando(false);
        }
    }

    async function alternarStatus(a) {
        const ativar = !a.is_active;
        setAlert('');
        if (!ativar) {
            const ok = await confirm({
                title: 'Desativar administrador',
                message: `Desativar ${a.name}? Ele não conseguirá mais entrar no sistema até ser reativado.`,
                confirmLabel: 'Desativar', danger: true,
            });
            if (!ok) return;
        }
        try {
            await definirStatusAdmin(a.id, ativar);
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        }
    }

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
            <div className="px-4 pt-4 pb-2">
                <h2 className="font-display text-primary font-semibold">Administradores cadastrados</h2>
                {alert && <div className="mt-2"><Alert>{alert}</Alert></div>}
            </div>
            {admins === null ? (
                <div className="px-4 py-8 text-center text-on-surface-variant">
                    <span className="inline-block w-6 h-6 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : admins.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-on-surface-variant">Nenhum administrador encontrado.</p>
            ) : (
                admins.map((a) => (
                    <LinhaAdmin
                        key={a.id}
                        admin={a}
                        souEu={a.id === user?.id}
                        editando={editId === a.id}
                        form={form}
                        setForm={setForm}
                        err={err}
                        salvando={salvando}
                        escopos={escopos}
                        escopoIds={escopoPorAdmin[a.id]?.escopo_ids ?? []}
                        onEscopos={(ids) => trocarEscopos(a.id, ids)}
                        onEditar={() => abrirEdicao(a)}
                        onCancelar={() => { setEditId(null); setErrors({}); }}
                        onSalvar={() => salvar(a.id)}
                        onStatus={() => alternarStatus(a)}
                        onDemo={() => alternarDemo(a)}
                    />
                ))
            )}
            {dialogo}
        </div>
    );
}

export default function AdminManager() {
    const [recarregar, setRecarregar] = useState(0);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Gestão de administradores</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Adicione e gerencie os administradores do sistema.
            </p>

            <div className="space-y-6 max-w-3xl">
                <CriarAdminForm onCriado={() => setRecarregar((n) => n + 1)} />
                <AdminList key={recarregar} />
            </div>
        </AppShell>
    );
}
