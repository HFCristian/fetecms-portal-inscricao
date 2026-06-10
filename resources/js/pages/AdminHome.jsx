import { useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Field, Input, Button, Alert } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getDashboard, getProjetosPorArea, criarAdmin } from '../lib/admin.js';

const CARDS = [
    { key: 'projetos_total', label: 'Projetos (total)', icon: 'folder', color: 'text-on-surface' },
    { key: 'projetos_submetidos', label: 'Submetidos', icon: 'task_alt', color: 'text-secondary' },
    { key: 'projetos_rascunho', label: 'Rascunhos', icon: 'edit_note', color: 'text-primary-container' },
    { key: 'orientadores', label: 'Orientadores', icon: 'person', color: 'text-on-surface' },
    { key: 'alunos', label: 'Alunos', icon: 'school', color: 'text-on-surface' },
    { key: 'coorientadores', label: 'Coorientadores', icon: 'group', color: 'text-on-surface' },
    { key: 'escolas_com_projeto', label: 'Escolas com projeto', icon: 'apartment', color: 'text-on-surface' },
    { key: 'cidades_com_projeto', label: 'Cidades com projeto', icon: 'location_city', color: 'text-on-surface' },
    { key: 'estados_com_projeto', label: 'Estados com projeto', icon: 'map', color: 'text-on-surface' },
];

function CriarAdminForm() {
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
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors(fields); setAlert(message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <form onSubmit={onSubmit} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-xl space-y-4">
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

const STATUS_PILL = {
    rascunho: 'bg-primary-fixed text-primary-container',
    submetido: 'bg-secondary-container text-on-secondary-container',
    aprovado: 'bg-secondary-container text-on-secondary-container',
    rejeitado: 'bg-error-container text-on-error-container',
};

function ProjetosPorArea() {
    const [grupos, setGrupos] = useState(null);

    useEffect(() => { getProjetosPorArea().then(setGrupos).catch(() => setGrupos([])); }, []);

    return (
        <section className="mb-10">
            <h2 className="font-display text-primary font-semibold mb-1">Projetos por área do conhecimento</h2>
            <p className="text-sm text-on-surface-variant mb-4">
                Inclui rascunhos. Projetos sem área aparecem em “Área ainda não informada”.
            </p>

            {grupos === null ? (
                <div className="text-center py-6 text-on-surface-variant">
                    <span className="material-symbols-outlined animate-spin">progress_activity</span>
                </div>
            ) : grupos.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">
                    Nenhum projeto cadastrado ainda.
                </div>
            ) : (
                <div className="space-y-4">
                    {grupos.map((g) => (
                        <div key={g.area_id ?? 'sem-area'} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <div className="flex items-center justify-between mb-3">
                                <h3 className="font-semibold text-on-surface">{g.area}</h3>
                                <span className="text-xs text-on-surface-variant">{g.total} projeto(s)</span>
                            </div>
                            <ul className="divide-y divide-outline-variant/30">
                                {g.projetos.map((p) => (
                                    <li key={p.id} className="py-2 flex items-center gap-3">
                                        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full shrink-0 ${STATUS_PILL[p.status] ?? ''}`}>
                                            {p.status_label}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium text-on-surface truncate">
                                                {p.titulo || <span className="italic text-on-surface-variant">Sem título</span>}
                                            </p>
                                            <p className="text-xs text-on-surface-variant truncate">
                                                {[p.orientador, p.categoria_label].filter(Boolean).join(' · ') || '—'}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

export default function AdminHome() {
    const [metricas, setMetricas] = useState(null);

    useEffect(() => { getDashboard().then(setMetricas).catch(() => setMetricas({})); }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Painel do Administrador</h1>
            <p className="text-on-surface-variant mb-6">Visão geral da XVI FETECMS.</p>

            {!metricas ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="material-symbols-outlined animate-spin">progress_activity</span>
                </div>
            ) : (
                <div className="grid grid-cols-2 md:grid-cols-3 gap-4 mb-10">
                    {CARDS.map((c) => (
                        <div key={c.key} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <span className="material-symbols-outlined text-primary-container">{c.icon}</span>
                            <div className={`text-3xl font-bold mt-1 ${c.color}`}>{metricas[c.key] ?? 0}</div>
                            <div className="text-xs text-on-surface-variant">{c.label}</div>
                        </div>
                    ))}
                </div>
            )}

            <ProjetosPorArea />

            <CriarAdminForm />
        </AppShell>
    );
}
