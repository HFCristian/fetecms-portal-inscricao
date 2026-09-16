import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input } from '../components/ui.jsx';
import BuscaCombobox from '../components/BuscaCombobox.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getCredenciais, criarCredencial, atualizarCredencial, excluirCredencial,
    atribuirCredencial, retirarCredencial, baixarPremiacao,
} from '../lib/presencial.js';

const VAZIA = { nome: '', orgao: '', vagas: '', descricao: '' };

/** Quantas vagas ainda cabem — "—" quando a credencial não tem teto. */
function Vagas({ credencial }) {
    if (credencial.vagas === null) {
        return (
            <span className="text-xs text-on-surface-variant">
                {credencial.usadas} atribuída(s) · sem limite de vagas
            </span>
        );
    }

    const esgotada = credencial.disponiveis === 0;

    return (
        <span className={`text-xs font-semibold ${esgotada ? 'text-error' : 'text-on-surface-variant'}`}>
            {credencial.usadas} de {credencial.vagas} vaga(s)
            {esgotada ? ' · esgotada' : ` · ${credencial.disponiveis} disponível(is)`}
        </span>
    );
}

/** Uma credencial: o que é, quem já recebeu e o campo para anexar mais um. */
function CartaoCredencial({ credencial, candidatos, ocupado, onAtribuir, onRetirar, onAlternar, onExcluir }) {
    const [projeto, setProjeto] = useState(null);

    const opcoes = candidatos
        .filter((c) => !credencial.projetos.some((p) => p.id === c.id))
        .map((c) => ({
            id: c.id,
            nome: c.titulo,
            detalhe: [c.categoria, c.area, c.credenciais.length ? `já tem: ${c.credenciais.join(', ')}` : null]
                .filter(Boolean).join(' · '),
        }));

    return (
        <li className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className={`font-semibold ${credencial.ativa ? 'text-on-surface' : 'text-on-surface-variant line-through'}`}>
                        {credencial.nome}
                        {credencial.orgao && <span className="text-on-surface-variant font-normal"> · {credencial.orgao}</span>}
                    </p>
                    {credencial.descricao && (
                        <p className="text-xs text-on-surface-variant mt-0.5">{credencial.descricao}</p>
                    )}
                    <Vagas credencial={credencial} />
                </div>
                <div className="flex gap-2 shrink-0">
                    <Button type="button" variant="outline" disabled={ocupado} onClick={() => onAlternar(credencial)}>
                        {credencial.ativa ? 'Desativar' : 'Reativar'}
                    </Button>
                    {credencial.usadas === 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            className="text-error border-error/40 hover:bg-error-container/40"
                            disabled={ocupado}
                            onClick={() => onExcluir(credencial)}
                        >
                            Excluir
                        </Button>
                    )}
                </div>
            </div>

            {credencial.projetos.length > 0 && (
                <ul className="mt-3 divide-y divide-outline-variant/30">
                    {credencial.projetos.map((p) => (
                        <li key={p.id} className="py-2 flex flex-wrap items-center gap-2">
                            <div className="min-w-0 flex-1">
                                <p className="text-sm text-on-surface truncate">{p.titulo}</p>
                                <p className="text-xs text-on-surface-variant">
                                    {[p.categoria, p.area].filter(Boolean).join(' · ')}
                                    {p.observacao ? ` · ${p.observacao}` : ''}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={ocupado}
                                onClick={() => onRetirar(credencial, p)}
                            >
                                Retirar
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            {credencial.ativa && (
                <div className="mt-3 flex flex-col sm:flex-row gap-2 sm:items-center">
                    <div className="flex-1">
                        <BuscaCombobox
                            options={opcoes}
                            value={projeto}
                            onChange={setProjeto}
                            placeholder="Buscar entre os finalistas…"
                            vazio="Nenhum finalista disponível"
                        />
                    </div>
                    <Button
                        type="button"
                        disabled={!projeto || ocupado}
                        onClick={() => { onAtribuir(credencial, projeto); setProjeto(null); }}
                    >
                        <span className="material-symbols-outlined text-[20px]">workspace_premium</span>
                        Anexar
                    </Button>
                </div>
            )}
        </li>
    );
}

/**
 * Avaliação presencial → **Credenciais**.
 *
 * Credencial é a vaga de premiação que a feira tem para dar: a indicação a uma
 * feira nacional, a bolsa de um parceiro, o prêmio de um órgão. Aqui elas são
 * cadastradas, anexadas a projetos **finalistas** e reunidas na **lista de
 * premiação** que se lê na cerimônia.
 */
export default function PresencialCredenciais() {
    const [credenciais, setCredenciais] = useState(null);
    const [candidatos, setCandidatos] = useState([]);
    const [form, setForm] = useState(VAZIA);
    const [criando, setCriando] = useState(false);
    const [ocupado, setOcupado] = useState(false);
    const [errors, setErrors] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');

    const carregar = useCallback(() => getCredenciais()
        .then((r) => { setCredenciais(r.data); setCandidatos(r.meta?.candidatos ?? []); })
        .catch(() => { setCredenciais([]); setAlerta('Não foi possível carregar as credenciais.'); }), []);

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
                <h1 className="font-display text-2xl font-semibold text-primary">Credenciais</h1>
                <Button type="button" variant="outline" onClick={() => baixarPremiacao()}>
                    <span className="material-symbols-outlined text-[20px]">download</span>
                    Lista de premiação (TXT)
                </Button>
            </div>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                As vagas de premiação da feira — indicações a outras feiras, bolsas, prêmios de
                parceiros. Cadastre o que há para dar, anexe cada credencial a um projeto{' '}
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
                                nome: form.nome.trim(),
                                orgao: form.orgao.trim() || null,
                                descricao: form.descricao.trim() || null,
                                vagas: form.vagas === '' ? null : Number(form.vagas),
                            });
                            setForm(VAZIA);
                            setCriando(false);
                        }, 'Credencial cadastrada.');
                    }}
                >
                    <h2 className="font-display text-primary font-semibold mb-3">Nova credencial</h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                        Nova credencial
                    </Button>
                </div>
            )}

            {credenciais === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : credenciais.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhuma credencial cadastrada nesta edição.
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
                                'Credencial anexada ao projeto.',
                            )}
                            onRetirar={(cred, projeto) => acao(
                                () => retirarCredencial(cred.id, projeto.id),
                                'Credencial retirada.',
                            )}
                            onAlternar={(cred) => acao(() => atualizarCredencial(cred.id, { ativa: !cred.ativa }))}
                            onExcluir={(cred) => acao(() => excluirCredencial(cred.id), 'Credencial excluída.')}
                        />
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
