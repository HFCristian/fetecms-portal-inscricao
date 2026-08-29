import { useState } from 'react';
import { Field, Input, Button, Alert, useContagemRegressiva, formatarMmSs } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { reenviarCodigo, trocarEmailCadastro } from '../lib/cadastro.js';

/**
 * Última etapa do cadastro (orientador e avaliador): a conta ainda não existe.
 * A pessoa confere na tela o e-mail que digitou, recebe um código de 6 dígitos
 * e o devolve aqui — só então o usuário é criado.
 *
 * Errou o endereço? Corrige na própria tela, sem refazer o formulário: o
 * cadastro preenchido continua guardado no servidor.
 */
export default function ConfirmacaoEmail({ pendente, onConfirmado, onConfirmar }) {
    const [cadastro, setCadastro] = useState(pendente);
    const [codigo, setCodigo] = useState('');
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [aviso, setAviso] = useState('');
    const [loading, setLoading] = useState(false);
    const [editandoEmail, setEditandoEmail] = useState(false);
    const [novoEmail, setNovoEmail] = useState(pendente.email ?? '');
    // Enquanto a contagem não zera, o botão de reenviar fica travado (é a mesma
    // janela que o servidor exige entre dois envios).
    const [liberaEm, setLiberaEm] = useState(() => Date.now() + 60_000);
    const faltam = useContagemRegressiva(liberaEm);

    const err = (name) => errors[name]?.[0];

    function falhou(error) {
        const { message, fields } = extractErrors(error);
        setErrors(fields);
        setAlert(message);
    }

    async function confirmar(e) {
        e.preventDefault();
        setAlert('');
        setAviso('');
        setErrors({});
        setLoading(true);
        try {
            const user = await onConfirmar(cadastro.token, codigo);
            onConfirmado(user);
        } catch (error) {
            falhou(error);
        } finally {
            setLoading(false);
        }
    }

    async function reenviar() {
        setAlert('');
        setErrors({});
        setLoading(true);
        try {
            const r = await reenviarCodigo(cadastro.token);
            setCadastro(r.data);
            setAviso(r.meta?.message ?? 'Enviamos um novo código.');
            setLiberaEm(Date.now() + 60_000);
        } catch (error) {
            falhou(error);
        } finally {
            setLoading(false);
        }
    }

    async function salvarEmail(e) {
        e.preventDefault();
        setAlert('');
        setErrors({});
        setLoading(true);
        try {
            const r = await trocarEmailCadastro(cadastro.token, novoEmail);
            setCadastro(r.data);
            setNovoEmail(r.data.email);
            setEditandoEmail(false);
            setCodigo('');
            setAviso(r.meta?.message ?? 'Enviamos o código para o novo endereço.');
            setLiberaEm(Date.now() + 60_000);
        } catch (error) {
            falhou(error);
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="p-6 sm:p-10 flex flex-col gap-5">
            <div>
                <h2 className="font-display text-2xl font-semibold text-primary">Confirme seu e-mail</h2>
                <p className="text-on-surface-variant text-sm mt-1">
                    Enviamos um código de 6 dígitos para o endereço abaixo. Ele vale por{' '}
                    {cadastro.validade_minutos ?? 15} minutos.
                </p>
            </div>

            <Alert>{alert}</Alert>
            <Alert type="info">{aviso}</Alert>

            {/* O e-mail cadastrado fica visível: é o ponto em que a pessoa
                percebe o erro de digitação — e conserta sem perder o cadastro. */}
            {editandoEmail ? (
                <form onSubmit={salvarEmail} className="flex flex-col gap-3">
                    <Field label="E-mail" required error={err('email')}>
                        <Input
                            type="email"
                            value={novoEmail}
                            onChange={(e) => setNovoEmail(e.target.value)}
                            error={err('email')}
                            autoFocus
                        />
                    </Field>
                    <div className="flex gap-3">
                        <Button type="submit" loading={loading}>Salvar e reenviar</Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => { setEditandoEmail(false); setNovoEmail(cadastro.email); }}
                        >
                            Cancelar
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-surface-container-low p-3">
                    <span className="text-sm text-on-surface break-all">
                        <span className="material-symbols-outlined text-[18px] align-[-4px] mr-1 text-primary-container">mail</span>
                        {cadastro.email}
                    </span>
                    <button
                        type="button"
                        className="text-sm font-semibold text-primary-container hover:text-primary"
                        onClick={() => setEditandoEmail(true)}
                    >
                        Não é este e-mail? Corrigir
                    </button>
                </div>
            )}

            <form onSubmit={confirmar} className="flex flex-col gap-4">
                <Field label="Código de confirmação" required error={err('codigo')}>
                    <Input
                        value={codigo}
                        onChange={(e) => setCodigo(e.target.value.replace(/\D/g, '').slice(0, 6))}
                        error={err('codigo')}
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        placeholder="000000"
                        style={{ letterSpacing: '0.4em', textAlign: 'center' }}
                    />
                </Field>

                <Button type="submit" className="w-full" loading={loading} disabled={codigo.length < 6}>
                    Confirmar e concluir cadastro
                </Button>
            </form>

            <div className="text-sm text-on-surface-variant">
                Não recebeu?{' '}
                <button
                    type="button"
                    className="font-semibold text-primary-container hover:text-primary disabled:text-on-surface-variant disabled:cursor-not-allowed"
                    onClick={reenviar}
                    disabled={loading || faltam > 0}
                >
                    Reenviar código
                </button>
                {faltam > 0 && <span> (disponível em {formatarMmSs(faltam)})</span>}
            </div>
        </div>
    );
}
