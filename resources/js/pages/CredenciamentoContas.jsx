import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getContasTemporarias, criarContaTemporaria,
    renovarContaTemporaria, desativarContaTemporaria,
} from '../lib/credenciamento.js';

/**
 * Credenciamento → **Contas temporárias**.
 *
 * Contas de prazo curto para quem atende o balcão sem ser da organização
 * (estudantes de um curso, em geral). O cadastro é o mesmo do administrador —
 * nome, e-mail e senha — mais **CPF**, **curso** e a **janela de acesso**:
 * quando começa e por quantas **horas** vale (padrão 5).
 *
 * Deixar "começa em" preenchido **agenda** a conta: ela fica cadastrada, mas só
 * entra no ar na hora marcada — é assim que a equipe inteira é preparada dias
 * antes do evento, sem ninguém criar conta no meio do balcão.
 *
 * Vencido o prazo, a conta é **desativada, não apagada**: reativar é informar
 * uma janela nova, sem recadastrar nada. E, enquanto existir, ela abre **só** a
 * aba Credenciamento, por cima de qualquer escopo.
 */
const VAZIO = {
    name: '', email: '', password: '', password_confirmation: '',
    cpf: '', curso: '', horas: '', valido_de: '',
};

function mascaraCpf(valor) {
    const d = (valor ?? '').replace(/\D/g, '').slice(0, 11);
    return d
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
}

// A situação sai da janela, não só do interruptor: a conta agendada está ativa
// mas ainda não abre, e mostrá-la como "Ativa" esconderia justamente o que o
// admin quer conferir na véspera do evento.
function SituacaoPill({ conta }) {
    const [txt, cor] = conta.vencida
        ? ['Prazo vencido', 'bg-error-container text-on-error-container']
        : !conta.ativa
            ? ['Encerrada', 'bg-surface-variant text-on-surface-variant']
            : conta.agendada
                ? [`Agendada · ${conta.valido_de_label}`, 'bg-primary-fixed text-primary-container']
                : [`Ativa · ${conta.duracao_label}`, 'bg-secondary-container text-on-secondary-container'];

    return <span className={`text-xs font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ${cor}`}>{txt}</span>;
}

function LinhaConta({ conta, onRenovar, onDesativar, ocupado, horasPadrao }) {
    const [horas, setHoras] = useState('');
    // Reagendar é o mesmo caminho de renovar: janela nova, começo novo.
    const [validoDe, setValidoDe] = useState('');
    const [renovando, setRenovando] = useState(false);

    return (
        <div className="px-4 py-3 border-b border-outline-variant/30 last:border-0">
            <div className="flex items-center gap-3 flex-wrap">
                <span className="material-symbols-outlined text-primary-container">badge</span>
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-on-surface truncate">{conta.nome}</span>
                        <SituacaoPill conta={conta} />
                    </div>
                    <p className="text-sm text-on-surface-variant truncate">{conta.email}</p>
                    <p className="text-xs text-on-surface-variant">
                        CPF {conta.cpf} · {conta.curso}
                        {conta.valido_de_label && ` · começa em ${conta.valido_de_label}`}
                        {' · vence em '}{conta.expira_em_label}
                        {conta.criada_por && ` · criada por ${conta.criada_por}`}
                    </p>
                </div>
                <div className="flex gap-1 shrink-0">
                    {!renovando && (
                        <button
                            type="button"
                            onClick={() => setRenovando(true)}
                            title={`Renovar o acesso de ${conta.nome}`}
                            className="p-1.5 rounded-lg text-secondary hover:bg-surface-variant transition-colors"
                        >
                            <span className="material-symbols-outlined text-[20px]">more_time</span>
                        </button>
                    )}
                    {conta.ativa && (
                        <button
                            type="button"
                            onClick={() => onDesativar(conta)}
                            disabled={ocupado}
                            title={`Encerrar o acesso de ${conta.nome}`}
                            className="p-1.5 rounded-lg text-error hover:bg-error-container disabled:opacity-30 transition-colors"
                        >
                            <span className="material-symbols-outlined text-[20px]">block</span>
                        </button>
                    )}
                </div>
            </div>

            {renovando && (
                <div className="mt-3 flex items-end gap-2 flex-wrap bg-surface-container-lowest rounded-lg p-3">
                    <Field label="Começa em" hint="Em branco, vale a partir de agora.">
                        <Input
                            type="datetime-local"
                            aria-label={`Início do acesso de ${conta.nome}`}
                            value={validoDe}
                            onChange={(e) => setValidoDe(e.target.value)}
                        />
                    </Field>
                    <Field label="Renovar por (horas)">
                        <Input
                            type="number"
                            min="1"
                            max="8760"
                            aria-label={`Horas de renovação de ${conta.nome}`}
                            value={horas}
                            onChange={(e) => setHoras(e.target.value)}
                            placeholder={String(horasPadrao)}
                        />
                    </Field>
                    <Button
                        type="button"
                        disabled={ocupado}
                        onClick={async () => {
                            await onRenovar(conta, {
                                horas: horas ? Number(horas) : undefined,
                                valido_de: validoDe || undefined,
                            });
                            setRenovando(false); setHoras(''); setValidoDe('');
                        }}
                    >
                        Renovar
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => { setRenovando(false); setHoras(''); setValidoDe(''); }}
                    >
                        Cancelar
                    </Button>
                </div>
            )}
        </div>
    );
}

export default function CredenciamentoContas() {
    const [dados, setDados] = useState(null);
    const [form, setForm] = useState(VAZIO);
    const [criando, setCriando] = useState(false);
    const [errors, setErrors] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [ocupado, setOcupado] = useState(false);
    const [confirm, dialogo] = useConfirm();

    useEffect(() => {
        getContasTemporarias()
            .then(setDados)
            .catch(() => setDados({ contas: [], horas_padrao: 5 }));
    }, []);

    function aplicar(resp) {
        setDados(resp.data);
        setSucesso(resp.meta?.message ?? '');
        setAlerta(''); setErrors({});
    }

    function falhar(e, padrao) {
        const { message, fields } = extractErrors(e);
        setErrors(fields ?? {});
        setAlerta(message || padrao);
        setSucesso('');
    }

    async function criar(ev) {
        ev.preventDefault();
        setOcupado(true);
        try {
            aplicar(await criarContaTemporaria({
                ...form,
                cpf: form.cpf.replace(/\D/g, ''),
                horas: form.horas ? Number(form.horas) : undefined,
                // Em branco, o backend entende "vale a partir de agora".
                valido_de: form.valido_de || undefined,
            }));
            setForm(VAZIO);
            setCriando(false);
        } catch (e) {
            falhar(e, 'Não foi possível criar a conta.');
        } finally {
            setOcupado(false);
        }
    }

    async function renovar(conta, janela = {}) {
        setOcupado(true);
        try {
            // Só o que foi preenchido viaja: o resto o backend resolve pelo padrão.
            const payload = Object.fromEntries(
                Object.entries(janela).filter(([, v]) => v !== undefined && v !== ''),
            );
            aplicar(await renovarContaTemporaria(conta.id, payload));
        } catch (e) {
            falhar(e, 'Não foi possível renovar o acesso.');
        } finally {
            setOcupado(false);
        }
    }

    async function desativar(conta) {
        const ok = await confirm({
            title: 'Encerrar o acesso?',
            message: `${conta.nome} perde o acesso ao credenciamento agora. A conta não é apagada — dá para renovar depois.`,
            confirmLabel: 'Encerrar',
        });
        if (!ok) return;

        setOcupado(true);
        try {
            aplicar(await desativarContaTemporaria(conta.id));
        } catch (e) {
            falhar(e, 'Não foi possível encerrar o acesso.');
        } finally {
            setOcupado(false);
        }
    }

    const campo = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
    const horasPadrao = dados?.horas_padrao ?? 5;

    return (
        <AppShell>
            <Link to="/admin/credenciamento" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Credenciamento
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Contas temporárias</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Acesso de prazo curto para quem atende o balcão sem fazer parte da organização. A conta
                abre <strong>somente</strong> a aba Credenciamento e é desativada no fim do prazo — para
                liberar de novo, basta informar uma janela nova, sem recadastrar nada. Deixando
                <strong> “começa em”</strong> preenchido, a conta fica <strong>agendada</strong>: dá para
                cadastrar toda a equipe dias antes e cada acesso abre sozinho na hora marcada.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            {criando ? (
                <form onSubmit={criar} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-3xl">
                    <h2 className="font-display text-primary font-semibold mb-4">Nova conta temporária</h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Field label="Nome" error={errors.name}>
                            <Input aria-label="Nome" value={form.name} onChange={campo('name')} error={errors.name} />
                        </Field>
                        <Field label="E-mail" error={errors.email}>
                            <Input type="email" aria-label="E-mail" value={form.email} onChange={campo('email')} error={errors.email} />
                        </Field>
                        <Field label="CPF" error={errors.cpf}>
                            <Input
                                aria-label="CPF"
                                value={form.cpf}
                                onChange={(e) => setForm((f) => ({ ...f, cpf: mascaraCpf(e.target.value) }))}
                                error={errors.cpf}
                                placeholder="000.000.000-00"
                            />
                        </Field>
                        <Field label="Nome do curso" error={errors.curso}>
                            <Input aria-label="Nome do curso" value={form.curso} onChange={campo('curso')} error={errors.curso} />
                        </Field>
                        <Field label="Senha" error={errors.password}>
                            <Input type="password" aria-label="Senha" value={form.password} onChange={campo('password')} error={errors.password} />
                        </Field>
                        <Field label="Confirmar senha" error={errors.password_confirmation}>
                            <Input type="password" aria-label="Confirmar senha" value={form.password_confirmation} onChange={campo('password_confirmation')} />
                        </Field>
                        <Field
                            label="Começa em"
                            error={errors.valido_de}
                            hint="Em branco, o acesso vale desde já."
                        >
                            <Input
                                type="datetime-local"
                                aria-label="Começa em"
                                value={form.valido_de}
                                onChange={campo('valido_de')}
                                error={errors.valido_de}
                            />
                        </Field>
                        <Field
                            label="Disponível por (horas)"
                            error={errors.horas || errors.expira_em}
                            hint={`Em branco, vale ${horasPadrao} horas a partir do início.`}
                        >
                            <Input
                                type="number"
                                min="1"
                                max="8760"
                                aria-label="Disponível por (horas)"
                                value={form.horas}
                                onChange={campo('horas')}
                                error={errors.horas}
                                placeholder={String(horasPadrao)}
                            />
                        </Field>
                    </div>
                    <div className="flex gap-2 justify-end mt-4">
                        <Button type="button" variant="outline" onClick={() => { setCriando(false); setErrors({}); }}>
                            Cancelar
                        </Button>
                        <Button type="submit" loading={ocupado}>Criar conta</Button>
                    </div>
                </form>
            ) : (
                <div className="mb-6">
                    <Button type="button" onClick={() => { setCriando(true); setAlerta(''); setSucesso(''); }}>
                        <span className="material-symbols-outlined text-[20px]">person_add</span>
                        Nova conta temporária
                    </Button>
                </div>
            )}

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow max-w-3xl overflow-hidden">
                {dados === null ? (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : dados.contas.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-on-surface-variant">
                        Nenhuma conta temporária criada nesta edição.
                    </p>
                ) : (
                    dados.contas.map((c) => (
                        <LinhaConta
                            key={c.id}
                            conta={c}
                            ocupado={ocupado}
                            horasPadrao={horasPadrao}
                            onRenovar={renovar}
                            onDesativar={desativar}
                        />
                    ))
                )}
            </div>

            {dialogo}
        </AppShell>
    );
}
