import { useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import http from '../lib/http.js';
import { extractErrors } from '../lib/auth.jsx';
import { Field, Input, Button, Alert } from '../components/ui.jsx';

const VAZIO = { current_password: '', password: '', password_confirmation: '' };

export default function AlterarSenha() {
    const [form, setForm] = useState(VAZIO);
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const [loading, setLoading] = useState(false);

    const set = (name) => (e) => setForm((f) => ({ ...f, [name]: e.target.value }));
    const err = (name) => errors[name]?.[0];

    async function onSubmit(e) {
        e.preventDefault();
        setAlert(''); setSuccess(''); setErrors({}); setLoading(true);
        try {
            const r = await http.put('/auth/senha', form);
            setSuccess(r.data?.data?.message || 'Senha alterada com sucesso.');
            setForm(VAZIO);
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors(fields);
            setAlert(message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Alterar senha</h1>
            <p className="text-on-surface-variant mb-6">Atualize a senha de acesso à sua conta.</p>

            <form onSubmit={onSubmit} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-md space-y-4">
                {alert && <Alert>{alert}</Alert>}
                {success && <Alert type="info">{success}</Alert>}

                <Field label="Senha atual" required error={err('current_password')}>
                    <Input
                        type="password"
                        autoComplete="current-password"
                        value={form.current_password}
                        onChange={set('current_password')}
                        error={err('current_password')}
                    />
                </Field>
                <Field label="Nova senha" required hint="Mínimo de 8 caracteres." error={err('password')}>
                    <Input
                        type="password"
                        autoComplete="new-password"
                        value={form.password}
                        onChange={set('password')}
                        error={err('password')}
                    />
                </Field>
                <Field label="Confirmar nova senha" required error={err('password_confirmation')}>
                    <Input
                        type="password"
                        autoComplete="new-password"
                        value={form.password_confirmation}
                        onChange={set('password_confirmation')}
                        error={err('password_confirmation')}
                    />
                </Field>

                <div className="flex justify-end pt-2">
                    <Button type="submit" loading={loading}>Salvar nova senha</Button>
                </div>
            </form>
        </AppShell>
    );
}
