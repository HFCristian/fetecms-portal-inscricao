import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, CpfInput, Field, Input, Select } from '../components/ui.jsx';
import SubareaCombobox from '../components/SubareaCombobox.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { loadAreas, loadSubareas } from '../lib/catalogos.js';
import { TITULACOES } from '../lib/avaliador.js';
import {
    getAvaliadoresCadastrados, cadastrarAvaliadorPresencial, novaSenhaAvaliadorPresencial,
} from '../lib/presencial.js';

const VAZIO = { name: '', email: '', cpf: '', titulacao: '', area_id: '', subarea_id: '', subarea_nome: '' };

/**
 * A senha gerada, mostrada uma única vez. O portal guarda só o hash: fechar
 * sem anotar é gerar outra depois.
 */
function SenhaGerada({ acesso, onFechar }) {
    const [copiado, setCopiado] = useState(false);

    async function copiar() {
        try {
            await navigator.clipboard.writeText(`${acesso.email}\n${acesso.senha}`);
            setCopiado(true);
        } catch {
            setCopiado(false);
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label="Senha gerada">
            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 w-full max-w-md">
                <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Acesso de {acesso.nome}</h2>
                <p className="text-sm text-on-surface-variant mb-4">
                    Entregue estes dados à pessoa. A senha <strong>não aparece de novo</strong> — se ela se
                    perder, gere outra nesta tela ou use “Esqueci a senha” no login.
                </p>
                <dl className="bg-surface rounded-lg border border-outline-variant p-3 text-sm space-y-2">
                    <div>
                        <dt className="text-xs text-on-surface-variant">E-mail</dt>
                        <dd className="font-medium text-on-surface break-all">{acesso.email}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-on-surface-variant">Senha</dt>
                        <dd className="font-mono text-lg tracking-wider text-primary-container" data-testid="senha-gerada">{acesso.senha}</dd>
                    </div>
                </dl>
                <div className="flex justify-end gap-2 mt-4">
                    <Button type="button" variant="outline" onClick={copiar}>
                        <span className="material-symbols-outlined text-[20px]">{copiado ? 'check' : 'content_copy'}</span>
                        {copiado ? 'Copiado' : 'Copiar'}
                    </Button>
                    <Button type="button" onClick={onFechar}>Anotei, fechar</Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Avaliação presencial → **Cadastro de avaliadores** (Sprint 191).
 *
 * Quem avalia só no dia da feira não passa pelo cadastro público: a
 * organização cria a conta aqui, com os mesmos dados do cadastro de avaliador
 * menos camiseta, idiomas, cidade e estado. A conta nasce pronta para o
 * presencial e fora da avaliação online, e a senha é gerada pelo portal.
 */
export default function PresencialAvaliadores() {
    const [form, setForm] = useState(VAZIO);
    const [areas, setAreas] = useState([]);
    const [subareas, setSubareas] = useState([]);
    const [errors, setErrors] = useState({});
    const [salvando, setSalvando] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [lista, setLista] = useState(null);
    const [busca, setBusca] = useState('');
    const [acesso, setAcesso] = useState(null);
    const [gerando, setGerando] = useState(null);

    useEffect(() => { loadAreas().then(setAreas).catch(() => setAreas([])); }, []);

    const carregar = useCallback((q = '') => getAvaliadoresCadastrados(q)
        .then(setLista)
        .catch(() => { setLista([]); setAlerta('Não foi possível carregar os avaliadores cadastrados.'); }), []);

    useEffect(() => { carregar(); }, [carregar]);

    const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
    const err = (k) => errors[k]?.[0];

    async function onAreaChange(areaId) {
        setForm((f) => ({ ...f, area_id: areaId, subarea_id: '', subarea_nome: '' }));
        setSubareas(areaId ? await loadSubareas(areaId) : []);
    }

    const subareaValue = form.subarea_id
        ? (subareas.find((s) => String(s.id) === String(form.subarea_id)) ?? null)
        : (form.subarea_nome ? { id: null, nome: form.subarea_nome } : null);

    function onSubareaChange(sel) {
        setForm((f) => ({ ...f, subarea_id: sel?.id ?? '', subarea_nome: sel && sel.id == null ? sel.nome : '' }));
    }

    async function cadastrar(e) {
        e.preventDefault();
        setSalvando(true); setErrors({}); setAlerta('');
        try {
            const resp = await cadastrarAvaliadorPresencial({
                name: form.name.trim(),
                email: form.email,
                cpf: form.cpf,
                titulacao: form.titulacao,
                area_id: form.area_id ? Number(form.area_id) : null,
                subarea_id: form.subarea_id || null,
                subarea_nome: form.subarea_nome || null,
            });
            setAcesso({ nome: resp.data.nome, email: resp.data.email, senha: resp.meta.senha });
            setForm(VAZIO);
            setSubareas([]);
            carregar(busca);
        } catch (e2) {
            const { message, fields } = extractErrors(e2);
            setErrors(fields ?? {});
            setAlerta(message || 'Não foi possível cadastrar.');
        } finally {
            setSalvando(false);
        }
    }

    async function novaSenha(a) {
        setGerando(a.id); setAlerta('');
        try {
            const resp = await novaSenhaAvaliadorPresencial(a.id);
            setAcesso({ nome: a.nome, email: a.email, senha: resp.meta.senha });
        } catch (e) {
            setAlerta(extractErrors(e).message || 'Não foi possível gerar a senha.');
        } finally {
            setGerando(null);
        }
    }

    return (
        <AppShell>
            <Link to="/admin/presencial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação presencial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Cadastro de avaliadores</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Para quem avalia <strong>só no dia da feira</strong>. A conta já nasce confirmada para a
                avaliação presencial e fica <strong>fora da avaliação online</strong>. O portal gera a senha
                e a mostra uma vez, para você entregar à pessoa.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}

            <form onSubmit={cadastrar} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-8 max-w-3xl" noValidate>
                <h2 className="font-display text-primary font-semibold mb-3">Novo avaliador</h2>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="sm:col-span-2">
                        <Field label="Nome completo" required error={err('name')}>
                            <Input aria-label="Nome completo" value={form.name} onChange={set('name')} error={err('name')} />
                        </Field>
                    </div>
                    <Field label="E-mail" required error={err('email')}>
                        <Input type="email" aria-label="E-mail" value={form.email} onChange={set('email')} error={err('email')} />
                    </Field>
                    <Field label="CPF" required error={err('cpf')}>
                        <CpfInput aria-label="CPF" value={form.cpf} onChange={set('cpf')} error={err('cpf')} />
                    </Field>
                    <Field label="Titulação" required error={err('titulacao')} hint="Pós-graduação em andamento já habilita.">
                        <Select aria-label="Titulação" value={form.titulacao} onChange={set('titulacao')} error={err('titulacao')}>
                            <option value="">Selecione</option>
                            {TITULACOES.map((t) => <option key={t} value={t}>{t}</option>)}
                        </Select>
                    </Field>
                    <Field label="Área de atuação" required error={err('area_id')}>
                        <Select aria-label="Área de atuação" value={form.area_id} onChange={(e) => onAreaChange(e.target.value)} error={err('area_id')}>
                            <option value="">Selecione</option>
                            {areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </Select>
                    </Field>
                    <div className="sm:col-span-2">
                        <Field label="Subárea" error={err('subarea_id') || err('subarea_nome')} hint="Opcional. Digite para buscar; se não existir, dá para criar.">
                            <SubareaCombobox
                                options={subareas}
                                value={subareaValue}
                                onChange={onSubareaChange}
                                disabled={!form.area_id}
                                placeholder={form.area_id ? 'Digite para buscar ou criar…' : 'Escolha a área primeiro'}
                            />
                        </Field>
                    </div>
                </div>
                <div className="flex justify-end mt-4">
                    <Button
                        type="submit"
                        loading={salvando}
                        disabled={!form.name.trim() || !form.email.trim() || !form.cpf || !form.titulacao || !form.area_id}
                    >
                        <span className="material-symbols-outlined text-[20px]">person_add</span>
                        Cadastrar e gerar senha
                    </Button>
                </div>
            </form>

            <section className="max-w-3xl" aria-label="Avaliadores cadastrados">
                <div className="flex flex-wrap items-end justify-between gap-3 mb-2">
                    <h2 className="font-display text-lg font-semibold text-on-surface">
                        Cadastrados para o presencial{lista ? ` (${lista.length})` : ''}
                    </h2>
                    <form onSubmit={(e) => { e.preventDefault(); carregar(busca); }} className="flex gap-2">
                        <Input aria-label="Buscar por nome ou e-mail" placeholder="Buscar por nome ou e-mail" value={busca} onChange={(e) => setBusca(e.target.value)} />
                        <Button type="submit" variant="outline">Buscar</Button>
                    </form>
                </div>

                {lista === null ? (
                    <div className="text-center py-8 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : lista.length === 0 ? (
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant">
                        Nenhum avaliador cadastrado para o presencial{busca ? ' com essa busca' : ''}.
                    </div>
                ) : (
                    <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/30">
                        {lista.map((a) => (
                            <li key={a.id} className="p-3 flex flex-wrap items-center gap-3">
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-medium text-on-surface">
                                        {a.nome}
                                        {!a.ativo && <span className="ml-2 text-xs text-error">desativado</span>}
                                        {a.comissao_especial && (
                                            <span className="ml-2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">Comissão</span>
                                        )}
                                    </p>
                                    <p className="text-xs text-on-surface-variant break-all">{a.email}</p>
                                    <p className="text-xs text-on-surface-variant">
                                        {[a.area, a.subarea, a.titulacao].filter(Boolean).join(' · ')}
                                        {' · '}{a.avaliacoes_presenciais} avaliação(ões) no estande
                                        {a.cadastrado_por ? ` · cadastrado por ${a.cadastrado_por}` : ''}
                                    </p>
                                </div>
                                <Button type="button" variant="outline" loading={gerando === a.id} disabled={gerando !== null} onClick={() => novaSenha(a)}>
                                    <span className="material-symbols-outlined text-[20px]">key</span>
                                    Gerar nova senha
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {acesso && <SenhaGerada acesso={acesso} onFechar={() => setAcesso(null)} />}
        </AppShell>
    );
}
