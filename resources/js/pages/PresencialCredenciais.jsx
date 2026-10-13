import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input } from '../components/ui.jsx';
import CartaoCredencial from '../components/CartaoCredencial.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getCredenciais, criarCredencial, atualizarCredencial, excluirCredencial,
    atribuirCredencial, retirarCredencial, baixarPremiacao,
} from '../lib/presencial.js';

const VAZIA = { tipo: 'credencial', nome: '', orgao: '', vagas: '', descricao: '', informacoes: '' };

/**
 * Avaliação presencial → **Credenciais e Prêmios**.
 *
 * Credencial é a vaga que a feira tem para dar e o projeto leva embora: a
 * indicação a uma feira nacional, a bolsa de um parceiro. **Prêmio** é o que
 * se anuncia no palco e não vira crachá — o destaque, a menção honrosa. Os
 * dois se cadastram aqui, anexam-se a projetos **finalistas** e saem juntos na
 * **lista de premiação** que se lê na cerimônia.
 *
 * A distinção só pesa no cerimonial: quem recebe qualquer um dos dois é
 * **premiado** (e rende medalha), mas só a credencial entra no card de
 * *credenciais a separar*, porque só ela tem objeto na mesa.
 */
export default function PresencialCredenciais() {
    const [credenciais, setCredenciais] = useState(null);
    const [candidatos, setCandidatos] = useState([]);
    const [tipos, setTipos] = useState([{ value: 'credencial', label: 'Credencial', descricao: '' }]);
    const [form, setForm] = useState(VAZIA);
    const [criando, setCriando] = useState(false);
    const [ocupado, setOcupado] = useState(false);
    const [errors, setErrors] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');

    const carregar = useCallback(() => getCredenciais()
        .then((r) => {
            setCredenciais(r.data);
            setCandidatos(r.meta?.candidatos ?? []);
            if (r.meta?.tipos?.length) setTipos(r.meta.tipos);
        })
        .catch(() => { setCredenciais([]); setAlerta('Não foi possível carregar as credenciais e prêmios.'); }), []);

    useEffect(() => { carregar(); }, [carregar]);

    async function acao(fn, mensagem = '') {
        setOcupado(true); setAlerta(''); setSucesso(''); setErrors({});
        try {
            await fn();
            await carregar();
            if (mensagem) setSucesso(mensagem);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErrors(fields ?? {});
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
        } finally {
            setOcupado(false);
        }
    }

    const campo = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));

    return (
        <AppShell>
            <Link to="/admin/presencial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação presencial
            </Link>
            <div className="flex flex-wrap items-start justify-between gap-3 mb-1 max-w-3xl">
                <h1 className="font-display text-2xl font-semibold text-primary">Credenciais e Prêmios</h1>
                <Button type="button" variant="outline" onClick={() => baixarPremiacao()}>
                    <span className="material-symbols-outlined text-[20px]">download</span>
                    Lista de premiação (TXT)
                </Button>
            </div>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                O que a feira tem para dar. <strong>Credencial</strong> é a vaga que o projeto
                leva — indicação a outra feira, bolsa, convite de parceiro —, e é dela que sai a
                pilha a separar no cerimonial. <strong>Prêmio</strong> é o reconhecimento
                anunciado no palco, sem crachá a entregar. Anexe cada um a um projeto{' '}
                <strong>finalista</strong> e baixe a lista que se lê na cerimônia.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            {criando ? (
                <form
                    className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl"
                    onSubmit={(e) => {
                        e.preventDefault();
                        acao(async () => {
                            await criarCredencial({
                                tipo: form.tipo,
                                nome: form.nome.trim(),
                                orgao: form.orgao.trim() || null,
                                descricao: form.descricao.trim() || null,
                                informacoes: form.informacoes.trim() || null,
                                vagas: form.vagas === '' ? null : Number(form.vagas),
                            });
                            setForm(VAZIA);
                            setCriando(false);
                        }, form.tipo === 'premio' ? 'Prêmio cadastrado.' : 'Credencial cadastrada.');
                    }}
                >
                    <h2 className="font-display text-primary font-semibold mb-3">Novo item</h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Field
                            label="Tipo"
                            error={errors.tipo}
                            hint={tipos.find((t) => t.value === form.tipo)?.descricao}
                        >
                            <select
                                aria-label="Tipo"
                                value={form.tipo}
                                onChange={campo('tipo')}
                                className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                            >
                                {tipos.map((t) => (
                                    <option key={t.value} value={t.value}>{t.label}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Nome" error={errors.nome}>
                            <Input aria-label="Nome" value={form.nome} onChange={campo('nome')} error={errors.nome} placeholder="MOSTRATEC 2027" />
                        </Field>
                        <Field label="Órgão" error={errors.orgao}>
                            <Input aria-label="Órgão" value={form.orgao} onChange={campo('orgao')} placeholder="FUNDECT" />
                        </Field>
                        <Field label="Vagas" error={errors.vagas} hint="Em branco, sem limite.">
                            <Input type="number" min="1" max="999" aria-label="Vagas" value={form.vagas} onChange={campo('vagas')} error={errors.vagas} />
                        </Field>
                        <Field label="Descrição" error={errors.descricao}>
                            <Input aria-label="Descrição" value={form.descricao} onChange={campo('descricao')} />
                        </Field>
                        <div className="sm:col-span-2">
                            <Field
                                label="Informações adicionais"
                                error={errors.informacoes}
                                hint="Critérios, regras do órgão, o que a vaga cobre. Os avaliadores do comitê leem ao recomendar."
                            >
                                <textarea
                                    aria-label="Informações adicionais"
                                    rows={4}
                                    maxLength={5000}
                                    value={form.informacoes}
                                    onChange={campo('informacoes')}
                                    className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                                />
                            </Field>
                        </div>
                    </div>
                    <div className="flex justify-end gap-2 mt-4">
                        <Button type="button" variant="outline" onClick={() => { setCriando(false); setErrors({}); }}>
                            Cancelar
                        </Button>
                        <Button type="submit" loading={ocupado} disabled={form.nome.trim() === ''}>
                            Cadastrar
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="mb-6">
                    <Button type="button" onClick={() => { setCriando(true); setAlerta(''); setSucesso(''); }}>
                        <span className="material-symbols-outlined text-[20px]">add</span>
                        Nova credencial ou prêmio
                    </Button>
                </div>
            )}

            {credenciais === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : credenciais.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhuma credencial ou prêmio cadastrado nesta edição.
                </div>
            ) : (
                <ul className="space-y-3 max-w-3xl">
                    {credenciais.map((c) => (
                        <CartaoCredencial
                            key={c.id}
                            credencial={c}
                            candidatos={candidatos}
                            ocupado={ocupado}
                            onAtribuir={(cred, projeto) => acao(
                                () => atribuirCredencial(cred.id, projeto.id),
                                'Anexado ao projeto.',
                            )}
                            onRetirar={(cred, projeto) => acao(
                                () => retirarCredencial(cred.id, projeto.id),
                                'Retirado do projeto.',
                            )}
                            onAlternar={(cred) => acao(() => atualizarCredencial(cred.id, { ativa: !cred.ativa }))}
                            onSalvarInformacoes={(cred, texto) => acao(
                                () => atualizarCredencial(cred.id, { informacoes: texto.trim() || null }),
                                'Informações adicionais salvas.',
                            )}
                            onExcluir={(cred) => acao(() => excluirCredencial(cred.id), 'Item excluído.')}
                        />
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
