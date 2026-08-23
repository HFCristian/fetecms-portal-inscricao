import { useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import http from '../lib/http.js';
import { useAuth, extractErrors } from '../lib/auth.jsx';
import { Field, Input, Button, Alert } from '../components/ui.jsx';

/**
 * Dados de acesso da conta — e-mail e senha na mesma tela, para orientador,
 * avaliador e admin. O e-mail é o login, então a troca fica registrada na
 * trilha do admin; a senha exige a atual.
 */

function TrocarEmail() {
    const { user, setUser } = useAuth();
    const [email, setEmail] = useState('');
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const [loading, setLoading] = useState(false);

    const err = (name) => errors[name]?.[0];

    async function onSubmit(e) {
        e.preventDefault();
        setAlert(''); setSuccess(''); setErrors({}); setLoading(true);
        try {
            const r = await http.put('/auth/email', { email });
            setUser(r.data.data);
            setSuccess(`Pronto! Seu e-mail de acesso agora é ${r.data.data.email}.`);
            setEmail('');
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors(fields);
            setAlert(message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <form onSubmit={onSubmit} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-md space-y-4">
            <div>
                <h2 className="font-display text-lg font-semibold text-primary">E-mail de acesso</h2>
                <p className="text-sm text-on-surface-variant mt-1">
                    É com ele que você entra no portal e recebe os avisos da organização.
                </p>
            </div>

            {alert && <Alert>{alert}</Alert>}
            {success && <Alert type="info">{success}</Alert>}

            <Field label="E-mail atual">
                <Input type="email" aria-label="E-mail atual" value={user?.email ?? ''} disabled readOnly />
            </Field>
            <Field
                label="Novo e-mail"
                required
                hint="Você passará a entrar no portal com este endereço."
                error={err('email')}
            >
                <Input
                    type="email"
                    aria-label="Novo e-mail"
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={err('email')}
                />
            </Field>

            <div className="flex justify-end pt-2">
                <Button type="submit" loading={loading}>Salvar novo e-mail</Button>
            </div>
        </form>
    );
}

const SENHA_VAZIA = { current_password: '', password: '', password_confirmation: '' };

function TrocarSenha() {
    const [form, setForm] = useState(SENHA_VAZIA);
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
            setForm(SENHA_VAZIA);
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors(fields);
            setAlert(message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <form onSubmit={onSubmit} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-md space-y-4">
            <div>
                <h2 className="font-display text-lg font-semibold text-primary">Senha</h2>
                <p className="text-sm text-on-surface-variant mt-1">
                    Para trocar, confirme a senha que você usa hoje.
                </p>
            </div>

            {alert && <Alert>{alert}</Alert>}
            {success && <Alert type="info">{success}</Alert>}

            <Field label="Senha atual" required error={err('current_password')}>
                <Input
                    type="password"
                    aria-label="Senha atual"
                    autoComplete="current-password"
                    value={form.current_password}
                    onChange={set('current_password')}
                    error={err('current_password')}
                />
            </Field>
            <Field label="Nova senha" required hint="Mínimo de 8 caracteres." error={err('password')}>
                <Input
                    type="password"
                    aria-label="Nova senha"
                    autoComplete="new-password"
                    value={form.password}
                    onChange={set('password')}
                    error={err('password')}
                />
            </Field>
            <Field label="Confirmar nova senha" required error={err('password_confirmation')}>
                <Input
                    type="password"
                    aria-label="Confirmar nova senha"
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
    );
}

export default function Acesso() {
    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Acesso</h1>
            <p className="text-on-surface-variant mb-6">
                Os dados que você usa para entrar no portal.
            </p>

            <div className="space-y-6">
                <TrocarEmail />
                <TrocarSenha />
            </div>
        </AppShell>
    );
}
