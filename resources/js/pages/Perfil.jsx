import { useState } from 'react';
import { useAuth, extractErrors } from '../lib/auth.jsx';
import AppShell from '../components/AppShell.jsx';
import http from '../lib/http.js';
import { Field, Input, Button, Alert } from '../components/ui.jsx';

export default function Perfil() {
    const { user, setUser } = useAuth();
    const profile = user?.orientador_profile ?? {};

    const [form, setForm] = useState({
        name: user?.name ?? '',
        email: user?.email ?? '',
        telefone: profile.telefone ?? '',
        instituicao: profile.instituicao ?? '',
        titulacao: profile.titulacao ?? '',
        cidade: profile.endereco?.cidade ?? '',
        estado: profile.endereco?.estado ?? '',
    });
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
            const r = await http.put('/perfil', form);
            setUser(r.data.data);
            setSuccess('Perfil atualizado com sucesso.');
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
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Meu Perfil</h1>
            <p className="text-on-surface-variant mb-6">Dados do orientador responsável.</p>

            <form onSubmit={onSubmit} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-2xl space-y-4">
                {alert && <Alert>{alert}</Alert>}
                {success && <Alert type="info">{success}</Alert>}

                <Field label="CPF" hint="O CPF não pode ser alterado por aqui.">
                    <Input value={profile.cpf ?? ''} disabled />
                </Field>
                <Field label="Nome Completo" error={err('name')}>
                    <Input value={form.name} onChange={set('name')} error={err('name')} />
                </Field>
                <Field label="E-mail" error={err('email')}>
                    <Input type="email" value={form.email} onChange={set('email')} error={err('email')} />
                </Field>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Field label="Telefone" error={err('telefone')}>
                        <Input value={form.telefone} onChange={set('telefone')} error={err('telefone')} />
                    </Field>
                    <Field label="Instituição">
                        <Input value={form.instituicao} onChange={set('instituicao')} />
                    </Field>
                    <Field label="Cidade">
                        <Input value={form.cidade} onChange={set('cidade')} />
                    </Field>
                    <Field label="Estado">
                        <Input value={form.estado} onChange={set('estado')} />
                    </Field>
                </div>

                <div className="pt-2">
                    <Button type="submit" loading={loading}>
                        <span className="material-symbols-outlined text-[20px]">save</span>
                        SALVAR ALTERAÇÕES
                    </Button>
                </div>
            </form>
        </AppShell>
    );
}
