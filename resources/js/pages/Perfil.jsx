import { useEffect, useState } from 'react';
import { useAuth, extractErrors } from '../lib/auth.jsx';
import AppShell from '../components/AppShell.jsx';
import http from '../lib/http.js';
import { Field, Input, CpfInput, TelefoneInput, CepInput, Select, Button, Alert } from '../components/ui.jsx';
import SubareaCombobox from '../components/SubareaCombobox.jsx';
import { listaPaises } from '../lib/paises.js';
import { useCatalogos, loadCidades, loadSubareas, criarSubarea } from '../lib/catalogos.js';

const PAISES = listaPaises();

export default function Perfil() {
    const { user, setUser } = useAuth();
    const profile = user?.orientador_profile ?? {};
    const endereco = profile.endereco ?? {};
    const catalogos = useCatalogos();
    const [cidades, setCidades] = useState([]);
    const [subareas, setSubareas] = useState([]);

    const [form, setForm] = useState({
        name: user?.name ?? '',
        email: user?.email ?? '',
        telefone: profile.telefone ?? '',
        instituicao: profile.instituicao ?? '',
        titulacao: profile.titulacao ?? '',
        area_id: profile.area_id ?? '',
        subarea_id: profile.subarea_id ?? '',
        pais: endereco.pais ?? 'BR',
        cep: endereco.cep ?? '',
        estado_id: endereco.estado_id ?? '',
        cidade_id: endereco.cidade_id ?? '',
        estado_nome: endereco.estado_nome ?? '',
        cidade_nome: endereco.cidade_nome ?? '',
    });
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const [loading, setLoading] = useState(false);

    const ehBR = (form.pais || 'BR') === 'BR';

    // Pré-carrega as cidades do estado já salvo, para o select de cidade vir preenchido.
    useEffect(() => {
        if (endereco.estado_id) loadCidades(endereco.estado_id).then(setCidades).catch(() => {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [endereco.estado_id]);

    // Pré-carrega as subáreas da área já salva, para o combobox listar/exibir.
    useEffect(() => {
        if (profile.area_id) loadSubareas(profile.area_id).then(setSubareas).catch(() => {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [profile.area_id]);

    const set = (name) => (e) => setForm((f) => ({ ...f, [name]: e.target.value }));
    const err = (name) => errors[name]?.[0];

    // Endereço: no Brasil é catálogo em cascata (estado→cidade); fora do Brasil, texto livre.
    async function onEstadoChange(estadoId) {
        setForm((f) => ({ ...f, estado_id: estadoId, cidade_id: '' }));
        setCidades(estadoId ? await loadCidades(estadoId) : []);
    }
    function onPaisChange(pais) {
        setForm((f) => ({
            ...f,
            pais,
            ...(pais === 'BR' ? { estado_nome: '', cidade_nome: '' } : { estado_id: '', cidade_id: '' }),
        }));
        if (pais !== 'BR') setCidades([]);
    }

    async function onAreaChange(areaId) {
        setForm((f) => ({ ...f, area_id: areaId, subarea_id: '' }));
        setSubareas(areaId ? await loadSubareas(areaId) : []);
    }
    async function criarSubareaNaArea(nome) {
        const nova = await criarSubarea(form.area_id, nome);
        setSubareas((prev) => (prev.some((s) => s.id === nova.id)
            ? prev
            : [...prev, nova].sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'))));
        return nova;
    }
    const subareaValue = form.subarea_id
        ? (subareas.find((s) => String(s.id) === String(form.subarea_id)) ?? null)
        : null;

    async function onSubmit(e) {
        e.preventDefault();
        setAlert(''); setSuccess(''); setErrors({}); setLoading(true);
        try {
            const r = await http.put('/perfil', form);
            setUser(r.data.data);
            setSuccess('Perfil atualizado com sucesso.');
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors(fields);
            setAlert(message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Meu Perfil</h1>
            <p className="text-on-surface-variant mb-6">Dados do orientador responsável.</p>

            <form onSubmit={onSubmit} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-2xl space-y-4">
                {alert && <Alert>{alert}</Alert>}
                {success && <Alert type="info">{success}</Alert>}

                <Field label="CPF" hint="O CPF não pode ser alterado por aqui.">
                    <CpfInput value={profile.cpf ?? ''} disabled />
                </Field>
                <Field label="Nome Completo" error={err('name')}>
                    <Input value={form.name} onChange={set('name')} error={err('name')} />
                </Field>
                <Field label="E-mail" error={err('email')}>
                    <Input type="email" value={form.email} onChange={set('email')} error={err('email')} />
                </Field>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Field label="Telefone" error={err('telefone')}>
                        <TelefoneInput value={form.telefone} onChange={set('telefone')} error={err('telefone')} />
                    </Field>
                    <Field label="Instituição">
                        <Input value={form.instituicao} onChange={set('instituicao')} />
                    </Field>
                    <Field label="Área do Conhecimento" error={err('area_id')}>
                        <Select value={form.area_id} onChange={(e) => onAreaChange(e.target.value)} error={err('area_id')}>
                            <option value="">Selecione</option>
                            {catalogos.areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </Select>
                    </Field>
                    <Field label="Subárea" error={err('subarea_id')}>
                        <SubareaCombobox
                            options={subareas}
                            value={subareaValue}
                            onChange={(sel) => setForm((f) => ({ ...f, subarea_id: sel?.id ?? '' }))}
                            create={criarSubareaNaArea}
                            disabled={!form.area_id}
                            placeholder={form.area_id ? 'Digite para buscar ou criar…' : 'Escolha a área primeiro'}
                        />
                    </Field>
                    <Field label="País">
                        <Select value={form.pais} onChange={(e) => onPaisChange(e.target.value)}>
                            {PAISES.map((p) => <option key={p.code} value={p.code}>{p.nome}</option>)}
                        </Select>
                    </Field>
                    {ehBR ? (
                        <Field label="CEP" error={err('cep')}>
                            <CepInput value={form.cep} onChange={set('cep')} error={err('cep')} />
                        </Field>
                    ) : (
                        <Field label="Código Postal">
                            <Input value={form.cep} onChange={set('cep')} placeholder="Código postal" />
                        </Field>
                    )}
                    {ehBR ? (
                        <>
                            <Field label="Estado" error={err('estado_id')}>
                                <Select value={form.estado_id} onChange={(e) => onEstadoChange(e.target.value)} error={err('estado_id')}>
                                    <option value="">Selecione</option>
                                    {catalogos.estados.map((es) => <option key={es.id} value={es.id}>{es.nome} ({es.uf})</option>)}
                                </Select>
                            </Field>
                            <Field label="Cidade" error={err('cidade_id')}>
                                <Select value={form.cidade_id} onChange={set('cidade_id')} error={err('cidade_id')} disabled={!form.estado_id}>
                                    <option value="">{form.estado_id ? 'Selecione' : 'Escolha o estado primeiro'}</option>
                                    {cidades.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                                </Select>
                            </Field>
                        </>
                    ) : (
                        <>
                            <Field label="Estado/Província">
                                <Input value={form.estado_nome} onChange={set('estado_nome')} placeholder="Ex: Buenos Aires" />
                            </Field>
                            <Field label="Cidade">
                                <Input value={form.cidade_nome} onChange={set('cidade_nome')} placeholder="Ex: La Plata" />
                            </Field>
                        </>
                    )}
                </div>

                <div className="pt-2">
                    <Button type="submit" loading={loading}>
                        <span className="material-symbols-outlined text-[20px]">save</span>
                        SALVAR ALTERAÇÕES
                    </Button>
                </div>
            </form>
        </AppShell>
    );
}
