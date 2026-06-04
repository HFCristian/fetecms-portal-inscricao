import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Field, Input, Select, Button, Alert } from '../components/ui.jsx';
import KeywordsInput from '../components/KeywordsInput.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { useCatalogos, loadSubareas, loadCidades } from '../lib/catalogos.js';
import { criarProjeto, atualizarProjeto, obterProjeto } from '../lib/projetos.js';

function contarPalavras(texto) {
    return (texto || '').trim().split(/\s+/).filter(Boolean).length;
}

export default function ProjetoForm() {
    const { id } = useParams();
    const navigate = useNavigate();
    const catalogos = useCatalogos();

    const [form, setForm] = useState({ pais: 'BR', palavras_chave: [], continuacao: false, feira_afiliada: false, agenda_2030: false });
    const [subareas, setSubareas] = useState([]);
    const [cidades, setCidades] = useState([]);
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);   // há alteração não salva?
    const [saved, setSaved] = useState(Boolean(id)); // projeto já existe (foi salvo)?
    const [loading, setLoading] = useState(Boolean(id));

    const err = (name) => errors[name]?.[0];

    // Carrega projeto em edição.
    useEffect(() => {
        if (!id) return;
        obterProjeto(id)
            .then(async (p) => {
                setForm({
                    titulo: p.titulo ?? '', categoria: p.categoria ?? '', instituicao_id: p.instituicao_id ?? '',
                    area_id: p.area_id ?? '', subarea_id: p.subarea_id ?? '', resumo: p.resumo ?? '',
                    link_video: p.link_video ?? '', palavras_chave: p.palavras_chave ?? [], pais: p.pais ?? 'BR',
                    estado_id: p.estado_id ?? '', cidade_id: p.cidade_id ?? '', email_comunicacao: p.email_comunicacao ?? '',
                    continuacao: p.continuacao, feira_afiliada: p.feira_afiliada, agenda_2030: p.agenda_2030,
                });
                if (p.area_id) setSubareas(await loadSubareas(p.area_id));
                if (p.estado_id) setCidades(await loadCidades(p.estado_id));
            })
            .catch(() => setAlert('Não foi possível carregar o projeto.'))
            .finally(() => setLoading(false));
    }, [id]);

    // Marca alteração pendente e some com a confirmação de "salvo".
    const markDirty = () => {
        setDirty(true);
        setSuccess('');
    };

    const setField = (name, value) => {
        setForm((f) => ({ ...f, [name]: value }));
        markDirty();
    };

    async function onAreaChange(areaId) {
        setForm((f) => ({ ...f, area_id: areaId, subarea_id: '' }));
        markDirty();
        setSubareas(areaId ? await loadSubareas(areaId) : []);
    }
    async function onEstadoChange(estadoId) {
        setForm((f) => ({ ...f, estado_id: estadoId, cidade_id: '' }));
        markDirty();
        setCidades(estadoId ? await loadCidades(estadoId) : []);
    }

    const buildPayload = useCallback(() => {
        const num = (v) => (v === '' || v == null ? null : Number(v));
        return {
            titulo: form.titulo || null,
            categoria: form.categoria || null,
            instituicao_id: num(form.instituicao_id),
            area_id: num(form.area_id),
            subarea_id: num(form.subarea_id),
            resumo: form.resumo || null,
            link_video: form.link_video || null,
            palavras_chave: form.palavras_chave,
            pais: form.pais || 'BR',
            estado_id: num(form.estado_id),
            cidade_id: num(form.cidade_id),
            email_comunicacao: form.email_comunicacao || null,
            continuacao: !!form.continuacao,
            feira_afiliada: !!form.feira_afiliada,
            agenda_2030: !!form.agenda_2030,
        };
    }, [form]);

    async function salvarRascunho() {
        setAlert(''); setSuccess(''); setErrors({}); setSaving(true);
        try {
            const payload = buildPayload();
            if (id) {
                await atualizarProjeto(id, payload);
                setSuccess('Rascunho salvo.');
                setSaved(true);
                setDirty(false);
            } else {
                const novo = await criarProjeto(payload);
                setSaved(true);
                setDirty(false);
                navigate(`/projetos/${novo.id}/editar`, { replace: true });
            }
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErrors(fields);
            setAlert(message);
        } finally {
            setSaving(false);
        }
    }

    if (loading) {
        return (
            <AppShell>
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="material-symbols-outlined animate-spin">progress_activity</span>
                </div>
            </AppShell>
        );
    }

    const palavras = contarPalavras(form.resumo);

    return (
        <AppShell>
            <Link to="/projetos" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-2">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span>
                Meus projetos
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">
                {id ? 'Editar Projeto' : 'Novo Projeto'}
            </h1>
            <p className="text-on-surface-variant mb-4">
                Preencha os dados do projeto. Você pode salvar como rascunho e voltar depois.
            </p>

            <div className="bg-primary-fixed/40 border-l-4 border-primary rounded-r-lg p-3 mb-6 text-sm text-on-surface">
                <strong>Status: rascunho.</strong> Upload de arquivos e submissão final entram na Sprint 3/4.
            </div>

            {alert && <div className="mb-4"><Alert>{alert}</Alert></div>}
            {success && <div className="mb-4"><Alert type="info">{success}</Alert></div>}

            <div className="space-y-6 max-w-3xl">
                {/* 1. Dados do projeto */}
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 space-y-4">
                    <h3 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">1. Dados do Projeto</h3>
                    <Field label="Título do Projeto" error={err('titulo')}>
                        <Input value={form.titulo ?? ''} onChange={(e) => setField('titulo', e.target.value)} error={err('titulo')} placeholder="Título completo do projeto" />
                    </Field>
                    <Field label="Instituição de Ensino" error={err('instituicao_id')}>
                        <Select value={form.instituicao_id ?? ''} onChange={(e) => setField('instituicao_id', e.target.value)}>
                            <option value="">Selecione</option>
                            {catalogos.instituicoes.map((i) => <option key={i.id} value={i.id}>{i.nome}</option>)}
                        </Select>
                    </Field>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Field label="Categoria" error={err('categoria')}>
                            <Select value={form.categoria ?? ''} onChange={(e) => setField('categoria', e.target.value)} error={err('categoria')}>
                                <option value="">Selecione</option>
                                {catalogos.categorias.map((c) => (
                                    <option key={c.value} value={c.value}>{c.label} (até {c.max_alunos} alunos)</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Área do Conhecimento" error={err('area_id')}>
                            <Select value={form.area_id ?? ''} onChange={(e) => onAreaChange(e.target.value)}>
                                <option value="">Selecione</option>
                                {catalogos.areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                            </Select>
                        </Field>
                        <Field label="Subárea">
                            <Select value={form.subarea_id ?? ''} onChange={(e) => setField('subarea_id', e.target.value)} disabled={!form.area_id}>
                                <option value="">{form.area_id ? 'Selecione' : 'Escolha a área primeiro'}</option>
                                {subareas.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
                            </Select>
                        </Field>
                    </div>
                    <Field label="Palavras-chave (3 a 5)" error={err('palavras_chave')}>
                        <KeywordsInput value={form.palavras_chave} onChange={(v) => setField('palavras_chave', v)} />
                    </Field>
                </section>

                {/* 2. Localização */}
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 space-y-4">
                    <h3 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">2. Localização</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Field label="Estado">
                            <Select value={form.estado_id ?? ''} onChange={(e) => onEstadoChange(e.target.value)}>
                                <option value="">Selecione</option>
                                {catalogos.estados.map((e) => <option key={e.id} value={e.id}>{e.nome} ({e.uf})</option>)}
                            </Select>
                        </Field>
                        <Field label="Cidade">
                            <Select value={form.cidade_id ?? ''} onChange={(e) => setField('cidade_id', e.target.value)} disabled={!form.estado_id}>
                                <option value="">{form.estado_id ? 'Selecione' : 'Escolha o estado primeiro'}</option>
                                {cidades.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                            </Select>
                        </Field>
                    </div>
                </section>

                {/* 3. Conteúdo */}
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 space-y-4">
                    <h3 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">3. Conteúdo</h3>
                    <Field label="Link do Vídeo/Apresentação" error={err('link_video')} hint="YouTube, Vimeo ou Google Drive (público).">
                        <Input type="url" value={form.link_video ?? ''} onChange={(e) => setField('link_video', e.target.value)} error={err('link_video')} placeholder="https://youtube.com/..." />
                    </Field>
                    <Field label={`Resumo do Projeto (${palavras} palavras)`} error={err('resumo')} hint="Entre 150 e 250 palavras na submissão.">
                        <textarea
                            value={form.resumo ?? ''}
                            onChange={(e) => setField('resumo', e.target.value)}
                            rows={6}
                            className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none resize-y"
                            placeholder="Objetivos, metodologia e conclusões..."
                        />
                    </Field>
                    <Field label="E-mail para comunicação" error={err('email_comunicacao')}>
                        <Input type="email" value={form.email_comunicacao ?? ''} onChange={(e) => setField('email_comunicacao', e.target.value)} error={err('email_comunicacao')} placeholder="email@instituicao.br" />
                    </Field>
                </section>

                {/* 4. Informações adicionais */}
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 space-y-3">
                    <h3 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">4. Informações Adicionais</h3>
                    {[
                        ['continuacao', 'Projeto de continuação?'],
                        ['feira_afiliada', 'Participou de feira afiliada?'],
                        ['agenda_2030', 'Relacionado à Agenda 2030 (ODS)?'],
                    ].map(([key, label]) => (
                        <label key={key} className="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" checked={!!form[key]} onChange={(e) => setField(key, e.target.checked)} className="w-5 h-5 rounded text-primary-container" />
                            <span className="text-sm text-on-surface">{label}</span>
                        </label>
                    ))}
                </section>

                {/* Ações */}
                <div className="flex flex-col sm:flex-row justify-end gap-3 pt-2">
                    <Button variant="outline" onClick={() => navigate('/projetos')} type="button">
                        Voltar
                    </Button>
                    <Button onClick={salvarRascunho} loading={saving} disabled={!dirty} type="button">
                        <span className="material-symbols-outlined text-[20px]">
                            {!dirty && saved ? 'check' : 'save'}
                        </span>
                        {!dirty && saved ? 'RASCUNHO SALVO' : 'SALVAR RASCUNHO'}
                    </Button>
                    <Button variant="success" type="button" disabled title="Submissão na Sprint 4">
                        <span className="material-symbols-outlined text-[20px]">send</span>
                        SUBMETER (Sprint 4)
                    </Button>
                </div>
            </div>
        </AppShell>
    );
}
