import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import InstituicaoCombobox from '../components/InstituicaoCombobox.jsx';
import { Alert, Button, Field, Input, Select } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { loadAreas, loadSubareas, buscarInstituicoes, criarInstituicao } from '../lib/catalogos.js';
import {
    getProjetosManuais, getProjetoManual, criarProjetoManual, atualizarProjetoManual,
    excluirProjetoManual, buscarOrientadores,
} from '../lib/admin.js';

const ALUNO_VAZIO = { nome: '', email: '', cpf: '' };

const VAZIO = {
    titulo: '', categoria: '', instituicao: null, area_id: '', subarea_id: '',
    origem: 'finalista', feira_afiliada_nome: '', numero_credencial: '',
    orientadorModo: 'novo', orientadorExistente: null,
    orientador: { nome: '', email: '', cpf: '', telefone: '' },
    temCoorientador: false, coorientador: { nome: '', email: '', cpf: '' },
    alunos: [{ ...ALUNO_VAZIO }],
    credenciado: false, justificativa: '',
};

const primeira = (v) => (Array.isArray(v) ? v[0] : v);

/**
 * Avaliação online → Listas finais → **Cadastro manual de projetos** (Sprint 157).
 *
 * Projetos que vão ao evento sem ter passado pela inscrição — a vaga veio de uma
 * **feira afiliada**, ou a organização recebeu a equipe só com os nomes. O
 * cadastro já põe o projeto na **lista final vigente** (é ela que o faz existir
 * para o credenciamento, o mapa, os crachás e a avaliação presencial) e, se
 * marcado, já o deixa **credenciado** no balcão. Ele fica fora da avaliação
 * online.
 *
 * CPF e e-mail de estudantes e coorientador são opcionais: costuma-se ter só o
 * nome. O orientador é uma conta — escolhida entre as que existem ou criada aqui
 * (a pessoa entra depois por "Esqueci a senha").
 */
export default function AvaliacaoProjetosManuais() {
    const [dados, setDados] = useState(null);
    const [categorias, setCategorias] = useState([]);
    const [areas, setAreas] = useState([]);
    const [subareas, setSubareas] = useState([]);
    const [form, setForm] = useState(null);
    const [editando, setEditando] = useState(null);
    const [erros, setErros] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [busca, setBusca] = useState('');
    const [candidatos, setCandidatos] = useState([]);
    const [excluindo, setExcluindo] = useState(null);

    const carregar = useCallback(() => {
        getProjetosManuais()
            .then((r) => { setDados(r.data); setCategorias(r.meta?.categorias ?? []); })
            .catch(() => setAlerta('Não foi possível carregar os projetos.'));
    }, []);

    useEffect(() => {
        carregar();
        loadAreas().then(setAreas).catch(() => setAreas([]));
    }, [carregar]);

    useEffect(() => {
        if (!form?.area_id) { setSubareas([]); return; }
        loadSubareas(form.area_id).then(setSubareas).catch(() => setSubareas([]));
    }, [form?.area_id]);

    // A base de orientadores é grande: quem filtra é o servidor.
    useEffect(() => {
        if (form?.orientadorModo !== 'existente') return undefined;
        const t = setTimeout(() => {
            buscarOrientadores(busca.trim()).then(setCandidatos).catch(() => setCandidatos([]));
        }, 300);
        return () => clearTimeout(t);
    }, [busca, form?.orientadorModo]);

    const muda = (campo, valor) => setForm((f) => ({ ...f, [campo]: valor }));
    const mudaEm = (grupo, campo, valor) => setForm((f) => ({ ...f, [grupo]: { ...f[grupo], [campo]: valor } }));
    const mudaAluno = (i, campo, valor) => setForm((f) => ({
        ...f, alunos: f.alunos.map((a, j) => (j === i ? { ...a, [campo]: valor } : a)),
    }));

    function novo() {
        setForm({ ...VAZIO, alunos: [{ ...ALUNO_VAZIO }] });
        setEditando(null); setErros({}); setAlerta(''); setSucesso('');
    }

    async function editar(id) {
        setAlerta(''); setSucesso(''); setErros({});
        try {
            const p = await getProjetoManual(id);
            setEditando(id);
            setForm({
                ...VAZIO,
                titulo: p.titulo,
                categoria: p.categoria ?? '',
                instituicao: p.instituicao,
                area_id: p.area_id ?? '',
                subarea_id: p.subarea?.id ?? '',
                origem: p.origem,
                feira_afiliada_nome: p.feira_afiliada_nome ?? '',
                numero_credencial: p.numero_credencial ?? '',
                orientadorModo: 'existente',
                orientadorExistente: p.orientador,
                temCoorientador: !!p.coorientador,
                coorientador: p.coorientador ? { nome: p.coorientador.nome, email: p.coorientador.email ?? '', cpf: p.coorientador.cpf ?? '' } : { ...VAZIO.coorientador },
                alunos: p.alunos.map((a) => ({ id: a.id, nome: a.nome, email: a.email ?? '', cpf: a.cpf ?? '' })),
                credenciado: p.credenciado,
                jaCredenciado: p.credenciado,
            });
        } catch {
            setAlerta('Não foi possível abrir o projeto.');
        }
    }

    function payload() {
        return {
            titulo: form.titulo.trim(),
            categoria: form.categoria,
            instituicao_id: form.instituicao?.id ?? null,
            area_id: form.area_id || null,
            subarea_id: form.subarea_id || null,
            origem: form.origem,
            feira_afiliada_nome: form.origem === 'credencial' ? form.feira_afiliada_nome : null,
            numero_credencial: form.origem === 'credencial' ? form.numero_credencial : null,
            orientador: form.orientadorModo === 'existente'
                ? { user_id: form.orientadorExistente?.id ?? null }
                : { ...form.orientador },
            coorientador: form.temCoorientador ? { ...form.coorientador } : null,
            alunos: form.alunos.map((a) => ({ ...(a.id ? { id: a.id } : {}), nome: a.nome, email: a.email, cpf: a.cpf })),
            credenciado: form.credenciado,
            justificativa: form.justificativa.trim(),
        };
    }

    async function salvar(ev) {
        ev.preventDefault();
        setSalvando(true); setAlerta(''); setErros({});
        try {
            const resp = editando
                ? await atualizarProjetoManual(editando, payload())
                : await criarProjetoManual(payload());
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? 'Salvo.');
            setForm(null); setEditando(null);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErros(fields ?? {});
            setAlerta(primeira(fields?.lista) || message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    async function excluir(justificativa) {
        setSalvando(true);
        try {
            const resp = await excluirProjetoManual(excluindo.id, justificativa);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? 'Projeto excluído.');
            setExcluindo(null);
        } catch (e) {
            setAlerta(extractErrors(e).message || 'Não foi possível excluir.');
        } finally {
            setSalvando(false);
        }
    }

    const err = (k) => primeira(erros[k]);
    const maxAlunos = form?.categoria === 'fetec_jr' ? 3 : 4;

    return (
        <AppShell>
            <Link to="/admin/avaliacao/listas-finais" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Listas finais
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Cadastro manual de projetos</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                Para quem vai ao evento sem ter passado pela inscrição: vaga de <strong>feira afiliada</strong> ou
                equipe que chegou só com os nomes. O projeto entra direto na <strong>lista final vigente</strong> —
                e daí no credenciamento, no mapa, nos crachás e na avaliação presencial — e fica fora da
                avaliação online. Cada cadastro pede justificativa e fica em Registros → Projetos.
            </p>

            {alerta && <div className="mb-4 max-w-4xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-4xl"><Alert type="info">{sucesso}</Alert></div>}

            {dados && !dados.lista && (
                <div className="mb-4 max-w-4xl">
                    <Alert>Não há lista final oficial publicada nesta edição. Publique a lista antes de cadastrar projetos à mão.</Alert>
                </div>
            )}

            {form ? (
                <form onSubmit={salvar} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6 max-w-4xl space-y-6" aria-label="Projeto manual">
                    <h2 className="font-display text-primary font-semibold">{editando ? 'Editar projeto' : 'Novo projeto'}</h2>

                    <section className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="md:col-span-2">
                            <Field label="Título" required error={err('titulo')}>
                                <Input aria-label="Título" value={form.titulo} onChange={(e) => muda('titulo', e.target.value)} />
                            </Field>
                        </div>
                        <Field label="Categoria" required error={err('categoria')}>
                            <Select aria-label="Categoria" value={form.categoria} onChange={(e) => muda('categoria', e.target.value)}>
                                <option value="">Escolha…</option>
                                {categorias.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                            </Select>
                        </Field>
                        <Field label="Instituição" required error={err('instituicao_id')}>
                            <InstituicaoCombobox
                                buscar={buscarInstituicoes}
                                create={criarInstituicao}
                                value={form.instituicao}
                                onChange={(sel) => muda('instituicao', sel)}
                                placeholder="Digite para buscar ou criar…"
                            />
                        </Field>
                        <Field label="Área" required error={err('area_id')}>
                            <Select
                                aria-label="Área"
                                value={form.area_id}
                                onChange={(e) => setForm((f) => ({ ...f, area_id: e.target.value, subarea_id: '' }))}
                            >
                                <option value="">Escolha…</option>
                                {areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                            </Select>
                        </Field>
                        <Field label="Subárea" error={err('subarea_id')} hint="Opcional.">
                            <Select aria-label="Subárea" value={form.subarea_id} onChange={(e) => muda('subarea_id', e.target.value)} disabled={!form.area_id}>
                                <option value="">Sem subárea</option>
                                {subareas.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
                            </Select>
                        </Field>
                    </section>

                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-2">Origem</legend>
                        <div className="flex flex-wrap gap-4 text-sm">
                            <label className="inline-flex items-center gap-2">
                                <input type="radio" name="origem" checked={form.origem === 'finalista'} onChange={() => muda('origem', 'finalista')} />
                                Finalista
                            </label>
                            <label className="inline-flex items-center gap-2">
                                <input type="radio" name="origem" checked={form.origem === 'credencial'} onChange={() => muda('origem', 'credencial')} />
                                Credencial de feira afiliada
                            </label>
                        </div>
                        {form.origem === 'credencial' && (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                                <Field label="Feira afiliada" required error={err('feira_afiliada_nome')}>
                                    <Input aria-label="Feira afiliada" value={form.feira_afiliada_nome} onChange={(e) => muda('feira_afiliada_nome', e.target.value)} />
                                </Field>
                                <Field label="Nº da credencial" error={err('numero_credencial')} hint="Opcional.">
                                    <Input aria-label="Nº da credencial" value={form.numero_credencial} onChange={(e) => muda('numero_credencial', e.target.value)} />
                                </Field>
                            </div>
                        )}
                    </fieldset>

                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-2">Orientador</legend>
                        <div className="flex flex-wrap gap-4 text-sm mb-3">
                            <label className="inline-flex items-center gap-2">
                                <input type="radio" name="orientador-modo" checked={form.orientadorModo === 'novo'} onChange={() => muda('orientadorModo', 'novo')} />
                                Novo (cria a conta)
                            </label>
                            <label className="inline-flex items-center gap-2">
                                <input type="radio" name="orientador-modo" checked={form.orientadorModo === 'existente'} onChange={() => muda('orientadorModo', 'existente')} />
                                Já tem conta no portal
                            </label>
                        </div>
                        {form.orientadorModo === 'novo' ? (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <Field label="Nome do orientador" required error={err('orientador.nome')}>
                                    <Input aria-label="Nome do orientador" value={form.orientador.nome} onChange={(e) => mudaEm('orientador', 'nome', e.target.value)} />
                                </Field>
                                <Field label="E-mail do orientador" required error={err('orientador.email')} hint="Se já houver conta com este e-mail, ela é usada.">
                                    <Input type="email" aria-label="E-mail do orientador" value={form.orientador.email} onChange={(e) => mudaEm('orientador', 'email', e.target.value)} />
                                </Field>
                                <Field label="CPF do orientador" error={err('orientador.cpf')} hint="Opcional.">
                                    <Input aria-label="CPF do orientador" value={form.orientador.cpf} onChange={(e) => mudaEm('orientador', 'cpf', e.target.value)} />
                                </Field>
                                <Field label="Telefone do orientador" error={err('orientador.telefone')} hint="Opcional.">
                                    <Input aria-label="Telefone do orientador" value={form.orientador.telefone} onChange={(e) => mudaEm('orientador', 'telefone', e.target.value)} />
                                </Field>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {form.orientadorExistente && (
                                    <p className="text-sm">
                                        Selecionado: <strong>{form.orientadorExistente.nome}</strong>{' '}
                                        <span className="text-on-surface-variant">({form.orientadorExistente.email})</span>
                                    </p>
                                )}
                                <Field label="Buscar orientador" error={err('orientador.user_id')}>
                                    <Input aria-label="Buscar orientador" placeholder="Nome ou e-mail" value={busca} onChange={(e) => setBusca(e.target.value)} />
                                </Field>
                                <ul className="max-h-48 overflow-y-auto border border-outline-variant/40 rounded-lg divide-y divide-outline-variant/30">
                                    {candidatos.map((c) => (
                                        <li key={c.id}>
                                            <button
                                                type="button"
                                                className="w-full text-left px-3 py-2 text-sm hover:bg-surface-variant"
                                                onClick={() => muda('orientadorExistente', c)}
                                            >
                                                {c.nome} <span className="text-on-surface-variant">· {c.email}</span>
                                            </button>
                                        </li>
                                    ))}
                                    {candidatos.length === 0 && <li className="px-3 py-2 text-sm text-on-surface-variant">Nenhum orientador encontrado.</li>}
                                </ul>
                            </div>
                        )}
                    </fieldset>

                    <fieldset>
                        <legend className="text-sm font-semibold text-on-surface mb-1">Estudantes</legend>
                        <p className="text-xs text-on-surface-variant mb-2">
                            Até {maxAlunos} nesta categoria. E-mail e CPF são opcionais — mas é o CPF que sai no
                            crachá e no certificado.
                        </p>
                        {err('alunos') && <div className="mb-2"><Alert>{err('alunos')}</Alert></div>}
                        <div className="space-y-3">
                            {form.alunos.map((a, i) => (
                                <div key={a.id ?? `n${i}`} className="grid grid-cols-1 md:grid-cols-[2fr_2fr_1fr_auto] gap-2 items-end">
                                    <Field label={`Estudante ${i + 1}`} error={err(`alunos.${i}.nome`)}>
                                        <Input aria-label={`Nome do estudante ${i + 1}`} value={a.nome} onChange={(e) => mudaAluno(i, 'nome', e.target.value)} />
                                    </Field>
                                    <Field label="E-mail" error={err(`alunos.${i}.email`)}>
                                        <Input type="email" aria-label={`E-mail do estudante ${i + 1}`} value={a.email} onChange={(e) => mudaAluno(i, 'email', e.target.value)} />
                                    </Field>
                                    <Field label="CPF" error={err(`alunos.${i}.cpf`)}>
                                        <Input aria-label={`CPF do estudante ${i + 1}`} value={a.cpf} onChange={(e) => mudaAluno(i, 'cpf', e.target.value)} />
                                    </Field>
                                    {form.alunos.length > 1 ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="text-error border-error/40"
                                            onClick={() => setForm((f) => ({ ...f, alunos: f.alunos.filter((_, j) => j !== i) }))}
                                        >
                                            Remover
                                        </Button>
                                    ) : <span />}
                                </div>
                            ))}
                        </div>
                        {form.alunos.length < maxAlunos && (
                            <Button type="button" variant="outline" className="mt-2" onClick={() => setForm((f) => ({ ...f, alunos: [...f.alunos, { ...ALUNO_VAZIO }] }))}>
                                <span className="material-symbols-outlined text-[20px]">add</span>
                                Acrescentar estudante
                            </Button>
                        )}
                    </fieldset>

                    <fieldset>
                        <label className="inline-flex items-center gap-2 text-sm font-semibold text-on-surface">
                            <input type="checkbox" checked={form.temCoorientador} onChange={(e) => muda('temCoorientador', e.target.checked)} />
                            Tem coorientador
                        </label>
                        {form.temCoorientador && (
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-2 mt-2">
                                <Field label="Nome do coorientador" error={err('coorientador.nome')}>
                                    <Input aria-label="Nome do coorientador" value={form.coorientador.nome} onChange={(e) => mudaEm('coorientador', 'nome', e.target.value)} />
                                </Field>
                                <Field label="E-mail" error={err('coorientador.email')}>
                                    <Input type="email" aria-label="E-mail do coorientador" value={form.coorientador.email} onChange={(e) => mudaEm('coorientador', 'email', e.target.value)} />
                                </Field>
                                <Field label="CPF" error={err('coorientador.cpf')}>
                                    <Input aria-label="CPF do coorientador" value={form.coorientador.cpf} onChange={(e) => mudaEm('coorientador', 'cpf', e.target.value)} />
                                </Field>
                            </div>
                        )}
                    </fieldset>

                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={form.credenciado}
                            disabled={form.jaCredenciado}
                            onChange={(e) => muda('credenciado', e.target.checked)}
                        />
                        <span>
                            <strong>Já credenciado no balcão</strong>
                            <span className="block text-xs text-on-surface-variant">
                                {form.jaCredenciado
                                    ? 'Este projeto já está credenciado. Para desfazer, use a aba Credenciamento.'
                                    : 'O projeto entra direto em Credenciamento → Credenciados, com a equipe toda presente.'}
                            </span>
                        </span>
                    </label>

                    <Field label="Justificativa" required error={err('justificativa')} hint="Fica em Registros → Projetos e → Lista final.">
                        <textarea
                            rows={3}
                            aria-label="Justificativa"
                            value={form.justificativa}
                            onChange={(e) => muda('justificativa', e.target.value)}
                            className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm"
                        />
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => { setForm(null); setEditando(null); }}>Cancelar</Button>
                        <Button type="submit" loading={salvando} disabled={form.justificativa.trim().length < 5}>
                            {editando ? 'Salvar alterações' : 'Cadastrar projeto'}
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="mb-6">
                    <Button type="button" onClick={novo} disabled={dados !== null && !dados.lista}>
                        <span className="material-symbols-outlined text-[20px]">add_circle</span>
                        Cadastrar projeto
                    </Button>
                </div>
            )}

            <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow max-w-4xl overflow-hidden">
                {dados === null ? (
                    <div className="text-center py-10">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin" role="status" aria-label="Carregando" />
                    </div>
                ) : dados.projetos.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-on-surface-variant">Nenhum projeto cadastrado à mão nesta edição.</p>
                ) : (
                    <ul className="divide-y divide-outline-variant/40">
                        {dados.projetos.map((p) => (
                            <li key={p.id} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                                <div className="flex-1 min-w-0">
                                    <p className="font-semibold text-on-surface">{p.titulo}</p>
                                    <p className="text-xs text-on-surface-variant">
                                        {p.categoria} · {p.area} · {p.instituicao}
                                    </p>
                                    <p className="text-xs text-on-surface-variant">
                                        Orientador: {p.orientador}
                                        {p.coorientador ? ` · Coorientador: ${p.coorientador}` : ''}
                                        {' · '}Estudantes: {p.alunos.join(', ')}
                                    </p>
                                    <div className="flex flex-wrap gap-1 mt-1">
                                        <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-primary-fixed text-primary-container">{p.origem_label}</span>
                                        {p.finalista
                                            ? <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">na lista final</span>
                                            : <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-error-container text-on-error-container">fora da lista final</span>}
                                        {p.credenciado && <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">credenciado</span>}
                                    </div>
                                </div>
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" onClick={() => editar(p.id)}>
                                        <span className="material-symbols-outlined text-[20px]">edit</span>
                                        Editar
                                    </Button>
                                    <Button type="button" variant="outline" className="text-error border-error/40" onClick={() => setExcluindo(p)}>
                                        <span className="material-symbols-outlined text-[20px]">delete</span>
                                        Excluir
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {excluindo && (
                <ExcluirDialog projeto={excluindo} salvando={salvando} onConfirmar={excluir} onFechar={() => setExcluindo(null)} />
            )}
        </AppShell>
    );
}

function ExcluirDialog({ projeto, salvando, onConfirmar, onFechar }) {
    const [justificativa, setJustificativa] = useState('');

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Excluir o projeto?</h3>
                    <p className="text-sm text-on-surface-variant">
                        “{projeto.titulo}” sai da lista final e do portal. A conta do orientador continua.
                    </p>
                </div>
                <textarea
                    rows={3}
                    aria-label="Justificativa da exclusão"
                    value={justificativa}
                    onChange={(e) => setJustificativa(e.target.value)}
                    className="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm"
                    placeholder="Por que este cadastro sai?"
                />
                <div className="flex justify-end gap-3">
                    <Button type="button" variant="outline" onClick={onFechar} disabled={salvando}>Cancelar</Button>
                    <Button type="button" loading={salvando} disabled={justificativa.trim().length < 5} onClick={() => onConfirmar(justificativa.trim())}>
                        Excluir
                    </Button>
                </div>
            </div>
        </div>
    );
}
