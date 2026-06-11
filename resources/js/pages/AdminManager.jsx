import { useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Field, Input, Button, Alert } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { criarAdmin } from '../lib/admin.js';

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



export default function AdminManager() {
    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Gestão de administradores</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Adicione e gerencie os administradores do sistema.
            </p>

            <CriarAdminForm />
        </AppShell>
    );
}
