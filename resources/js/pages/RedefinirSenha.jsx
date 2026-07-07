import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { redefinirSenha } from '../lib/senha.js';
import { extractErrors } from '../lib/auth.jsx';
import AuthCard from '../components/AuthCard.jsx';
import { Field, Input, Button, Alert } from '../components/ui.jsx';

export default function RedefinirSenha() {
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const token = params.get('token') || '';
    const email = params.get('email') || '';
    const linkInvalido = !token || !email;

    const [password, setPassword] = useState('');
    const [confirmacao, setConfirmacao] = useState('');
    const [showPass, setShowPass] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [ok, setOk] = useState(false);

    async function onSubmit(e) {
        e.preventDefault();
        setError('');
        setLoading(true);
        try {
            await redefinirSenha({
                token,
                email,
                password,
                password_confirmation: confirmacao,
            });
            setOk(true);
            setTimeout(() => navigate('/login', { replace: true }), 2500);
        } catch (err) {
            setError(extractErrors(err).message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <AuthCard>
            <div className="flex flex-col grow justify-center px-6 sm:px-10 py-10 w-full max-w-lg mx-auto">
                <h2 className="font-display text-2xl font-semibold text-on-surface mb-1">Criar nova senha</h2>
                <p className="text-sm text-on-surface-variant mb-8">
                    Defina uma nova senha para a sua conta no Portal da XVI FETECMS.
                </p>

                {linkInvalido ? (
                    <div className="space-y-6">
                        <Alert>
                            Link de redefinição inválido ou incompleto. Solicite um novo link de recuperação.
                        </Alert>
                        <Link
                            to="/esqueci-senha"
                            className="inline-flex items-center justify-center gap-2 text-sm font-semibold text-primary-container hover:underline"
                        >
                            <span className="material-symbols-outlined text-[20px]">refresh</span>
                            Solicitar novo link
                        </Link>
                    </div>
                ) : ok ? (
                    <div className="space-y-6">
                        <Alert type="info">
                            Senha redefinida com sucesso! Redirecionando para o login…
                        </Alert>
                        <Link
                            to="/login"
                            className="inline-flex items-center justify-center gap-2 text-sm font-semibold text-primary-container hover:underline"
                        >
                            <span className="material-symbols-outlined text-[20px]">login</span>
                            Ir para o login agora
                        </Link>
                    </div>
                ) : (
                    <>
                        <div className="mb-4">
                            <Alert>{error}</Alert>
                        </div>

                        <form onSubmit={onSubmit} className="space-y-5">
                            <Field label="E-mail">
                                <Input type="email" value={email} readOnly disabled autoComplete="username" />
                            </Field>

                            <Field label="Nova senha" required hint="Mínimo de 8 caracteres.">
                                <div className="relative">
                                    <Input
                                        type={showPass ? 'text' : 'password'}
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        placeholder="••••••••"
                                        autoComplete="new-password"
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPass((v) => !v)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-outline hover:text-primary-container"
                                    >
                                        <span className="material-symbols-outlined text-[20px]">
                                            {showPass ? 'visibility' : 'visibility_off'}
                                        </span>
                                    </button>
                                </div>
                            </Field>

                            <Field label="Confirmar nova senha" required>
                                <Input
                                    type={showPass ? 'text' : 'password'}
                                    value={confirmacao}
                                    onChange={(e) => setConfirmacao(e.target.value)}
                                    placeholder="••••••••"
                                    autoComplete="new-password"
                                    required
                                />
                            </Field>

                            <Button type="submit" loading={loading} className="w-full">
                                <span className="material-symbols-outlined text-[20px]">lock_reset</span>
                                REDEFINIR SENHA
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
