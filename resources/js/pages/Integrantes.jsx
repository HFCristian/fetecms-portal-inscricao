import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Field, Input, Select, Button, Alert } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { useCatalogos } from '../lib/catalogos.js';
import {
    getIntegrantes, criarAluno, atualizarAluno, removerAluno,
    salvarCoorientador, removerCoorientador,
} from '../lib/integrantes.js';

function PessoaCard({ titulo, nome, meta, onEdit, onRemove }) {
    return (
        <article className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex items-center gap-3">
            <div className="w-10 h-10 rounded-full bg-primary-fixed text-primary-container flex items-center justify-center font-bold">
                {(nome || '?').slice(0, 1).toUpperCase()}
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-xs text-on-surface-variant">{titulo}</p>
                <p className="font-semibold text-on-surface truncate">{nome}</p>
                <p className="text-xs text-on-surface-variant truncate">{meta}</p>
            </div>
            {onEdit && (
                <button onClick={onEdit} className="text-sm text-primary-container hover:underline">Editar</button>
            )}
            {onRemove && (
                <button onClick={onRemove} className="text-sm text-error hover:underline">Remover</button>
            )}
        </article>
    );
}

function AlunoForm({ catalogos, inicial, onSubmit, onCancelar }) {
    const [form, setForm] = useState(inicial ?? {});
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const set = (name) => (e) =>
        setForm((f) => ({ ...f, [name]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));
    const err = (name) => errors[name]?.[0];

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await onSubmit(form);
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors({ ...fields, _geral: [message] });
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={submit} className="bg-surface-container-low rounded-xl p-5 space-y-4 border border-outline-variant/40">
            <h3 className="font-display text-primary font-semibold">{inicial?.id ? 'Editar aluno' : 'Novo aluno'}</h3>
            {(errors.equipe || errors.categoria || errors._geral) && (
                <Alert>{errors.equipe?.[0] || errors.categoria?.[0] || errors._geral?.[0]}</Alert>
            )}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Field label="Nome" required error={err('nome')}>
                    <Input value={form.nome ?? ''} onChange={set('nome')} error={err('nome')} />
                </Field>
                <Field label="CPF" required error={err('cpf')}>
                    <Input value={form.cpf ?? ''} onChange={set('cpf')} error={err('cpf')} placeholder="000.000.000-00" />
                </Field>
                <Field label="E-mail" required error={err('email')}>
                    <Input type="email" value={form.email ?? ''} onChange={set('email')} error={err('email')} />
                </Field>
                <Field label="Telefone" error={err('telefone')}>
                    <Input value={form.telefone ?? ''} onChange={set('telefone')} placeholder="(00) 00000-0000" />
                </Field>
                <Field label="Data de Nascimento" error={err('data_nascimento')}>
                    <Input type="date" value={form.data_nascimento ?? ''} onChange={set('data_nascimento')} error={err('data_nascimento')} />
                </Field>
                <Field label="Gênero">
                    <Select value={form.genero ?? ''} onChange={set('genero')}>
                        <option value="">Selecione</option>
                        <option value="F">Feminino</option>
                        <option value="M">Masculino</option>
                        <option value="NB">Não-binário</option>
                        <option value="P">Prefiro não informar</option>
                    </Select>
                </Field>
                <Field label="Instituição de Ensino">
                    <Select value={form.instituicao_id ?? ''} onChange={set('instituicao_id')}>
                        <option value="">Selecione</option>
                        {catalogos.instituicoes.map((i) => <option key={i.id} value={i.id}>{i.nome}</option>)}
                    </Select>
                </Field>
                <Field label="Modalidade">
                    <Select value={form.modalidade ?? ''} onChange={set('modalidade')}>
                        <option value="">Selecione</option>
                        <option value="fundamental">Ensino Fundamental</option>
                        <option value="medio">Ensino Médio</option>
                        <option value="tecnico">Ensino Técnico</option>
                    </Select>
                </Field>
                <Field label="Tamanho da Camiseta">
                    <Select value={form.camiseta ?? ''} onChange={set('camiseta')}>
                        <option value="">Selecione</option>
                        {['PP', 'P', 'M', 'G', 'GG'].map((t) => <option key={t} value={t}>{t}</option>)}
                    </Select>
                </Field>
            </div>
            <div className="flex gap-4">
                <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" checked={!!form.bolsista} onChange={set('bolsista')} className="w-4 h-4" /> Bolsista CNPq/Fundect
                </label>
                <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" checked={!!form.clube_ciencias} onChange={set('clube_ciencias')} className="w-4 h-4" /> Clube de Ciências
                </label>
            </div>
            <div className="flex justify-end gap-3">
                <Button type="button" variant="outline" onClick={onCancelar}>Cancelar</Button>
                <Button type="submit" variant="success" loading={saving}>Salvar aluno</Button>
            </div>
        </form>
    );
}

function CoorientadorForm({ inicial, onSubmit, onCancelar }) {
    const [form, setForm] = useState(inicial ?? {});
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const set = (name) => (e) => setForm((f) => ({ ...f, [name]: e.target.value }));
    const err = (name) => errors[name]?.[0];

    async function submit(e) {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await onSubmit(form);
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors({ ...fields, _geral: [message] });
        } finally {
            setSaving(false);
        }
    }

    return (
        <form onSubmit={submit} className="bg-surface-container-low rounded-xl p-5 space-y-4 border border-outline-variant/40">
            <h3 className="font-display text-primary font-semibold">{inicial?.id ? 'Editar coorientador' : 'Novo coorientador'}</h3>
            {errors._geral && <Alert>{errors._geral[0]}</Alert>}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Field label="Nome" required error={err('nome')}>
                    <Input value={form.nome ?? ''} onChange={set('nome')} error={err('nome')} />
                </Field>
                <Field label="CPF" required error={err('cpf')}>
                    <Input value={form.cpf ?? ''} onChange={set('cpf')} error={err('cpf')} placeholder="000.000.000-00" />
                </Field>
                <Field label="E-mail" required error={err('email')}>
                    <Input type="email" value={form.email ?? ''} onChange={set('email')} error={err('email')} />
                </Field>
                <Field label="Telefone" error={err('telefone')}>
                    <Input value={form.telefone ?? ''} onChange={set('telefone')} placeholder="(00) 00000-0000" />
                </Field>
                <Field label="Tamanho da Camiseta">
                    <Select value={form.camiseta ?? ''} onChange={set('camiseta')}>
                        <option value="">Selecione</option>
                        {['PP', 'P', 'M', 'G', 'GG'].map((t) => <option key={t} value={t}>{t}</option>)}
                    </Select>
                </Field>
            </div>
            <div className="flex justify-end gap-3">
                <Button type="button" variant="outline" onClick={onCancelar}>Cancelar</Button>
                <Button type="submit" variant="success" loading={saving}>Salvar coorientador</Button>
            </div>
        </form>
    );
}

export default function Integrantes() {
    const { id } = useParams();
    const navigate = useNavigate();
    const catalogos = useCatalogos();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [erro, setErro] = useState(false);
    const [alunoForm, setAlunoForm] = useState(null);   // null | {} | aluno
    const [coorForm, setCoorForm] = useState(null);     // null | {} | coorientador

    const carregar = useCallback(() => {
        setLoading(true);
        getIntegrantes(id).then(setData).catch(() => setErro(true)).finally(() => setLoading(false));
    }, [id]);

    useEffect(() => carregar(), [carregar]);

    if (loading) {
        return (
            <AppShell>
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="material-symbols-outlined animate-spin">progress_activity</span>
                </div>
            </AppShell>
        );
    }

    if (erro || !data) {
        return (
            <AppShell>
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-10 text-center max-w-lg mx-auto">
                    <span className="material-symbols-outlined text-[48px] text-error">report</span>
                    <h1 className="font-display text-xl font-semibold text-on-surface mt-3">Projeto não encontrado</h1>
                    <p className="text-on-surface-variant text-sm mt-1">Este projeto não existe ou não está vinculado à sua conta.</p>
                    <Button className="mt-6" onClick={() => navigate('/projetos')}>Voltar aos meus projetos</Button>
                </div>
            </AppShell>
        );
    }

    const { projeto, limites, alunos, coorientador, orientador } = data;
    const editavel = projeto?.editavel ?? true;
    const podeAdicionarAluno = editavel && limites.categoria && limites.alunos_atual < limites.max_alunos;

    async function salvarAluno(form) {
        if (form.id) await atualizarAluno(form.id, form);
        else await criarAluno(id, form);
        carregar();
        setAlunoForm(null);
    }
    async function excluirAluno(aluno) {
        if (!window.confirm(`Remover ${aluno.nome}?`)) return;
        await removerAluno(aluno.id);
        carregar();
    }
    async function salvarCoor(form) {
        await salvarCoorientador(id, form);
        carregar();
        setCoorForm(null);
    }
    async function excluirCoor() {
        if (!window.confirm('Remover coorientador?')) return;
        await removerCoorientador(id);
        carregar();
    }

    return (
        <AppShell>
            <Link to="/projetos" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-2">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span>
                Meus projetos
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Integrantes</h1>

            {!editavel && (
                <div className="mb-4"><Alert type="info">Projeto submetido — os integrantes estão em modo somente leitura.</Alert></div>
            )}

            {/* Limites / categoria */}
            {limites.categoria ? (
                <p className="text-on-surface-variant mb-6">
                    Categoria <strong>{limites.categoria_label}</strong> · Alunos{' '}
                    <strong className="text-primary-container">{limites.alunos_atual}/{limites.max_alunos}</strong>{' '}
                    (mínimo {limites.min_alunos})
                </p>
            ) : (
                <div className="mb-6">
                    <Alert>
                        Defina a <strong>categoria</strong> do projeto antes de cadastrar alunos.{' '}
                        <Link to={`/projetos/${id}/editar`} className="underline font-semibold">Editar projeto</Link>
                    </Alert>
                </div>
            )}

            {/* Orientador */}
            <p className="text-sm font-semibold text-on-surface-variant mb-2">Orientador responsável</p>
            <div className="mb-6">
                <PessoaCard titulo="Orientador" nome={orientador.nome} meta={`${orientador.email}${orientador.telefone ? ' · ' + orientador.telefone : ''}`} />
            </div>

            {/* Alunos */}
            <div className="flex items-center justify-between mb-2">
                <p className="text-sm font-semibold text-on-surface-variant">Alunos</p>
                {editavel && (
                    <Button onClick={() => setAlunoForm({})} disabled={!podeAdicionarAluno || alunoForm !== null}
                        title={!limites.categoria ? 'Defina a categoria primeiro' : (!podeAdicionarAluno ? 'Limite atingido' : '')}>
                        <span className="material-symbols-outlined text-[18px]">person_add</span>
                        Adicionar aluno
                    </Button>
                )}
            </div>

            {alunoForm !== null && (
                <div className="mb-4">
                    <AlunoForm catalogos={catalogos} inicial={alunoForm} onSubmit={salvarAluno} onCancelar={() => setAlunoForm(null)} />
                </div>
            )}

            <div className="space-y-3 mb-8">
                {alunos.length === 0 && <p className="text-sm text-on-surface-variant">Nenhum aluno cadastrado ainda.</p>}
                {alunos.map((a, i) => (
                    <PessoaCard key={a.id} titulo={`Aluno ${i + 1}`} nome={a.nome}
                        meta={`${a.email}${a.camiseta ? ' · Camiseta ' + a.camiseta : ''}`}
                        onEdit={editavel ? () => setAlunoForm(a) : undefined}
                        onRemove={editavel ? () => excluirAluno(a) : undefined} />
                ))}
            </div>

            {/* Coorientador */}
            <div className="flex items-center justify-between mb-2">
                <p className="text-sm font-semibold text-on-surface-variant">Coorientador <span className="text-xs">(opcional, máx. 1)</span></p>
                {!coorientador && coorForm === null && editavel && (
                    <Button variant="outline" onClick={() => setCoorForm({})}>
                        <span className="material-symbols-outlined text-[18px]">group_add</span>
                        Adicionar coorientador
                    </Button>
                )}
            </div>

            {coorForm !== null && (
                <div className="mb-4">
                    <CoorientadorForm inicial={coorForm} onSubmit={salvarCoor} onCancelar={() => setCoorForm(null)} />
                </div>
            )}

            {coorientador && coorForm === null && (
                <PessoaCard titulo="Coorientador" nome={coorientador.nome}
                    meta={`${coorientador.email}${coorientador.telefone ? ' · ' + coorientador.telefone : ''}`}
                    onEdit={editavel ? () => setCoorForm(coorientador) : undefined}
                    onRemove={editavel ? excluirCoor : undefined} />
            )}
        </AppShell>
    );
}
