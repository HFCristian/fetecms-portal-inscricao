import { useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import http from '../lib/http.js';
import { useAuth, extractErrors } from '../lib/auth.jsx';
import { Field, Input, Button, Alert } from '../components/ui.jsx';

/**
 * Troca do e-mail de acesso — disponível para orientador, avaliador e admin.
 * O e-mail é o login da conta, então a mudança fica registrada na trilha do admin.
 */
export default function AlterarEmail() {
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
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Alterar e-mail</h1>
            <p className="text-on-surface-variant mb-6">
                Este é o e-mail que você usa para entrar no portal e receber os avisos da organização.
            </p>

            <form onSubmit={onSubmit} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-md space-y-4">
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
        </AppShell>
    );
}
