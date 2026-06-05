import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth, extractErrors, homeFor } from '../lib/auth.jsx';
import AuthCard from '../components/AuthCard.jsx';
import { Field, Input, Select, Button, Alert } from '../components/ui.jsx';
import { useCatalogos, loadSubareas } from '../lib/catalogos.js';

export default function CadastroAvaliador() {
    const { registerAvaliador } = useAuth();
    const navigate = useNavigate();
    const catalogos = useCatalogos();
    const [form, setForm] = useState({});
    const [subareas, setSubareas] = useState([]);
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [loading, setLoading] = useState(false);

    const set = (name) => (e) => setForm((f) => ({ ...f, [name]: e.target.value }));
    const err = (name) => errors[name]?.[0];

    async function onAreaChange(areaId) {
        setForm((f) => ({ ...f, area_id: areaId, subarea_id: '' }));
        setSubareas(areaId ? await loadSubareas(areaId) : []);
    }

    async function onSubmit(e) {
        e.preventDefault();
        setAlert('');
        setErrors({});
        setLoading(true);
        try {
            const user = await registerAvaliador({ ...form, password_confirmation: form.password_confirmation ?? '' });
            navigate(homeFor(user.role), { replace: true });
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors(fields);
            setAlert(message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <AuthCard>
            <div className="flex-grow flex flex-col justify-center px-6 sm:px-10 py-8 w-full max-w-lg mx-auto">
                <h2 className="font-display text-2xl font-semibold text-on-surface mb-1">Cadastro de Avaliador</h2>
                <p className="text-sm text-on-surface-variant mb-6">
                    Avaliadores analisam projetos submetidos. Quem é orientador (ou coorientador) não pode ser avaliador.
                </p>

                {alert && <div className="mb-4"><Alert>{alert}</Alert></div>}

                <form onSubmit={onSubmit} className="space-y-4">
                    <Field label="Nome Completo" required error={err('name')}>
                        <Input value={form.name ?? ''} onChange={set('name')} error={err('name')} />
                    </Field>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Field label="E-mail" required error={err('email')}>
                            <Input type="email" value={form.email ?? ''} onChange={set('email')} error={err('email')} />
                        </Field>
                        <Field label="CPF" required error={err('cpf')}>
                            <Input value={form.cpf ?? ''} onChange={set('cpf')} error={err('cpf')} placeholder="000.000.000-00" />
                        </Field>
                        <Field label="Senha" required error={err('password')} hint="Mínimo de 8 caracteres.">
                            <Input type="password" value={form.password ?? ''} onChange={set('password')} error={err('password')} />
                        </Field>
                        <Field label="Confirmar Senha" required>
                            <Input type="password" value={form.password_confirmation ?? ''} onChange={set('password_confirmation')} />
                        </Field>
                        <Field label="Titulação">
                            <Select value={form.titulacao ?? ''} onChange={set('titulacao')}>
                                <option value="">Selecione</option>
                                <option value="Graduação">Graduação</option>
                                <option value="Especialização">Especialização</option>
                                <option value="Mestrado">Mestrado</option>
                                <option value="Doutorado">Doutorado</option>
                            </Select>
                        </Field>
                        <Field label="Área de Atuação" required error={err('area_id')}>
                            <Select value={form.area_id ?? ''} onChange={(e) => onAreaChange(e.target.value)} error={err('area_id')}>
                                <option value="">Selecione</option>
                                {catalogos.areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                            </Select>
                        </Field>
                        <Field label="Subárea (preferencial para o match)">
                            <Select value={form.subarea_id ?? ''} onChange={set('subarea_id')} disabled={!form.area_id}>
                                <option value="">{form.area_id ? 'Selecione' : 'Escolha a área primeiro'}</option>
                                {subareas.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
                            </Select>
                        </Field>
                    </div>

                    <Button type="submit" loading={loading} className="w-full">
                        <span className="material-symbols-outlined text-[20px]">verified_user</span>
                        CRIAR CONTA DE AVALIADOR
                    </Button>
                </form>

                <p className="text-center text-sm text-on-surface-variant mt-6 pt-4 border-t border-outline-variant/30">
                    Já tem conta? <Link to="/login" className="font-semibold text-primary-container hover:underline">Entrar</Link>
                </p>
            </div>
        </AuthCard>
    );
}
