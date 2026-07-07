import { useState } from 'react';
import { Link } from 'react-router-dom';
import { esqueciSenha } from '../lib/senha.js';
import { extractErrors } from '../lib/auth.jsx';
import AuthCard from '../components/AuthCard.jsx';
import { Field, Input, Button, Alert } from '../components/ui.jsx';

export default function EsqueciSenha() {
    const [email, setEmail] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [enviado, setEnviado] = useState('');

    async function onSubmit(e) {
        e.preventDefault();
        setError('');
        setLoading(true);
        try {
            const r = await esqueciSenha(email);
            setEnviado(r.message);
        } catch (err) {
            setError(extractErrors(err).message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <AuthCard>
            <div className="flex flex-col grow justify-center px-6 sm:px-10 py-10 w-full max-w-lg mx-auto">
                <h2 className="font-display text-2xl font-semibold text-on-surface mb-1">Esqueci minha senha</h2>
                <p className="text-sm text-on-surface-variant mb-8">
                    Informe o e-mail cadastrado e enviaremos um link para você criar uma nova senha.
                </p>

                {enviado ? (
                    <div className="space-y-6">
                        <Alert type="info">{enviado}</Alert>
                        <p className="text-sm text-on-surface-variant">
                            O link expira em <strong>30 minutos</strong>. Não encontrou o e-mail? Verifique a
                            caixa de spam ou lixo eletrônico.
                        </p>
                        <Link
                            to="/login"
                            className="inline-flex items-center justify-center gap-2 text-sm font-semibold text-primary-container hover:underline"
                        >
                            <span className="material-symbols-outlined text-[20px]">arrow_back</span>
                            Voltar para o login
                        </Link>
                    </div>
                ) : (
                    <>
                        <div className="mb-4">
                            <Alert>{error}</Alert>
                        </div>

                        <form onSubmit={onSubmit} className="space-y-5">
                            <Field label="E-mail" required>
                                <Input
                                    type="email"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    placeholder="seu@email.com"
                                    autoComplete="email"
                                    required
                                />
                            </Field>

                            <Button type="submit" loading={loading} className="w-full">
                                <span className="material-symbols-outlined text-[20px]">mail</span>
                                ENVIAR LINK
                            </Button>
                        </form>

                        <div className="mt-8 text-center">
                            <Link
                                to="/login"
                                className="inline-flex items-center justify-center gap-2 text-sm font-semibold text-primary-container hover:underline"
                            >
                                <span className="material-symbols-outlined text-[20px]">arrow_back</span>
                                Voltar para o login
                            </Link>
                        </div>
                    </>
                )}
            </div>
        </AuthCard>
    );
}
