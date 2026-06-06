import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth, extractErrors, homeFor } from '../lib/auth.jsx';
import AuthCard from '../components/AuthCard.jsx';
import { Field, Input, Select, Button, Alert } from '../components/ui.jsx';

const STEPS = ['Dados Pessoais', 'Info. Acadêmicas', 'Endereço'];

// Mapeia cada campo à sua etapa, para saltar à etapa do primeiro erro de validação.
const FIELD_STEP = {
    name: 1, cpf: 1, data_nascimento: 1, email: 1, telefone: 1, password: 1,
    genero: 1, etnia: 1, camiseta: 1, pcd: 1,
    instituicao: 2, tipo_instituicao: 2, vinculo: 2, titulacao: 2, curso_formacao: 2,
    area_conhecimento: 2, subarea: 2, tempo_orientacao: 2, vezes_fetec: 2, ex_aluno_fetec: 2,
    cep: 3, pais: 3, estado: 3, cidade: 3, logradouro: 3, numero: 3, bairro: 3, complemento: 3,
};

export default function Cadastro() {
    const { register } = useAuth();
    const navigate = useNavigate();
    const [step, setStep] = useState(1);
    const [form, setForm] = useState({ pais: 'BR', pcd: false, ex_aluno_fetec: false });
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [loading, setLoading] = useState(false);

    const set = (name) => (e) => {
        const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
        setForm((f) => ({ ...f, [name]: value }));
    };
    const err = (name) => errors[name]?.[0];

    async function onSubmit(e) {
        e.preventDefault();
        // Trava: só efetiva o cadastro na última etapa (evita submit acidental).
        if (step < 3) {
            setStep((s) => s + 1);
            return;
        }
        setAlert('');
        setErrors({});
        setLoading(true);
        try {
            const user = await register({ ...form, password_confirmation: form.password_confirmation ?? '' });
            navigate(homeFor(user.role), { replace: true });
        } catch (error) {
            const { message, fields } = extractErrors(error);
            setErrors(fields);
            setAlert(message);
            const firstField = Object.keys(fields)[0];
            if (firstField && FIELD_STEP[firstField]) setStep(FIELD_STEP[firstField]);
        } finally {
            setLoading(false);
        }
    }

    const progress = step === 1 ? '15%' : step === 2 ? '50%' : '100%';

    return (
        <AuthCard>
            <div className="px-6 sm:px-10 pt-8 pb-4 border-b border-outline-variant/20">
                <h2 className="font-display text-xl font-semibold text-on-surface mb-4">Cadastro do Orientador</h2>
                <div className="flex items-center justify-between relative">
                    <div className="absolute top-1/2 left-0 w-full h-[2px] bg-surface-variant -translate-y-1/2" />
                    <div
                        className="absolute top-1/2 left-0 h-[2px] bg-primary-container -translate-y-1/2 transition-all duration-500"
                        style={{ width: progress }}
                    />
                    {STEPS.map((label, i) => {
                        const n = i + 1;
                        const active = n === step;
                        const done = n < step;
                        return (
                            <div key={n} className="flex flex-col items-center gap-1 bg-surface-container-lowest px-2 relative z-10">
                                <div
                                    className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold ${
                                        done ? 'bg-secondary text-on-secondary'
                                        : active ? 'bg-primary-container text-on-primary'
                                        : 'bg-surface-variant text-on-surface-variant'
                                    }`}
                                >
                                    {done ? <span className="material-symbols-outlined text-[16px]">check</span> : n}
                                </div>
                                <span className={`text-xs hidden sm:block ${active ? 'text-primary-container font-bold' : 'text-on-surface-variant'}`}>
                                    {label}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>

            <form onSubmit={onSubmit} className="flex flex-col">
                <div className="px-6 sm:px-10 py-6 space-y-4 overflow-y-auto max-h-[55vh]">
                    {alert && <Alert>{alert}</Alert>}

                    {step === 1 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="md:col-span-2">
                                <Field label="Nome Completo" required error={err('name')}>
                                    <Input value={form.name ?? ''} onChange={set('name')} error={err('name')} placeholder="Ex: Maria Clara da Silva" />
                                </Field>
                            </div>
                            <Field label="CPF" required error={err('cpf')}>
                                <Input value={form.cpf ?? ''} onChange={set('cpf')} error={err('cpf')} placeholder="000.000.000-00" />
                            </Field>
                            <Field label="Data de Nascimento" required error={err('data_nascimento')}>
                                <Input type="date" value={form.data_nascimento ?? ''} onChange={set('data_nascimento')} error={err('data_nascimento')} />
                            </Field>
                            <div className="md:col-span-2">
                                <Field label="E-mail" required error={err('email')}>
                                    <Input type="email" value={form.email ?? ''} onChange={set('email')} error={err('email')} placeholder="nome@instituicao.edu.br" />
                                </Field>
                            </div>
                            <Field label="Telefone/WhatsApp" required error={err('telefone')}>
                                <Input value={form.telefone ?? ''} onChange={set('telefone')} error={err('telefone')} placeholder="(00) 00000-0000" />
                            </Field>
                            <Field label="Gênero">
                                <Select value={form.genero ?? ''} onChange={set('genero')}>
                                    <option value="">Selecione</option>
                                    <option value="F">Feminino</option>
                                    <option value="M">Masculino</option>
                                    <option value="NB">Não-binário</option>
                                    <option value="O">Outro</option>
                                    <option value="P">Prefiro não informar</option>
                                </Select>
                            </Field>
                            <Field label="Senha" required error={err('password')} hint="Mínimo de 8 caracteres.">
                                <Input type="password" value={form.password ?? ''} onChange={set('password')} error={err('password')} placeholder="••••••••" />
                            </Field>
                            <Field label="Confirmar Senha" required>
                                <Input type="password" value={form.password_confirmation ?? ''} onChange={set('password_confirmation')} placeholder="••••••••" />
                            </Field>
                            <div className="md:col-span-2">
                                <Field label="Tamanho da Camiseta">
                                    <Select value={form.camiseta ?? ''} onChange={set('camiseta')}>
                                        <option value="">Selecione</option>
                                        {['PP', 'P', 'M', 'G', 'GG', 'XG'].map((t) => <option key={t} value={t}>{t}</option>)}
                                    </Select>
                                </Field>
                            </div>
                        </div>
                    )}

                    {step === 2 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="md:col-span-2">
                                <Field label="Instituição de Ensino">
                                    <Select value={form.instituicao ?? ''} onChange={set('instituicao')}>
                                        <option value="">Selecione</option>
                                        {['UFMS', 'UEMS', 'IFMS', 'UCDB'].map((i) => <option key={i} value={i}>{i}</option>)}
                                    </Select>
                                </Field>
                            </div>
                            <Field label="Tipo de Instituição">
                                <Select value={form.tipo_instituicao ?? ''} onChange={set('tipo_instituicao')}>
                                    <option value="">Selecione</option>
                                    <option value="publica_federal">Pública Federal</option>
                                    <option value="publica_estadual">Pública Estadual</option>
                                    <option value="publica_municipal">Pública Municipal</option>
                                    <option value="particular">Particular</option>
                                </Select>
                            </Field>
                            <Field label="Vínculo Institucional">
                                <Select value={form.vinculo ?? ''} onChange={set('vinculo')}>
                                    <option value="">Selecione</option>
                                    <option value="efetivo">Professor Efetivo</option>
                                    <option value="substituto">Professor Substituto</option>
                                    <option value="tecnico">Técnico Administrativo</option>
                                    <option value="pesquisador">Pesquisador</option>
                                </Select>
                            </Field>
                            <Field label="Titulação">
                                <Select value={form.titulacao ?? ''} onChange={set('titulacao')}>
                                    <option value="">Selecione</option>
                                    <option value="graduacao">Graduação</option>
                                    <option value="especializacao">Especialização</option>
                                    <option value="mestrado">Mestrado</option>
                                    <option value="doutorado">Doutorado</option>
                                    <option value="pos_doutorado">Pós-Doutorado</option>
                                </Select>
                            </Field>
                            <Field label="Curso de Formação">
                                <Input value={form.curso_formacao ?? ''} onChange={set('curso_formacao')} placeholder="Ex: Ciências Biológicas" />
                            </Field>
                            <Field label="Área do Conhecimento (CNPq)">
                                <Select value={form.area_conhecimento ?? ''} onChange={set('area_conhecimento')}>
                                    <option value="">Selecione</option>
                                    {['Exatas e da Terra', 'Biológicas', 'Engenharias', 'Saúde', 'Agrárias', 'Sociais Aplicadas', 'Humanas', 'Letras e Artes'].map((a) => <option key={a} value={a}>{a}</option>)}
                                </Select>
                            </Field>
                            <Field label="Subárea de Atuação">
                                <Input value={form.subarea ?? ''} onChange={set('subarea')} placeholder="Ex: Genética Molecular" />
                            </Field>
                            <Field label="Tempo de Experiência com Orientação">
                                <Select
                                    value={form.tempo_orientacao ?? ''}
                                    onChange={(e) => {
                                        const v = e.target.value;
                                        // Limpa "vezes na FETEC" quando for iniciante (pergunta some).
                                        setForm((f) => ({ ...f, tempo_orientacao: v, vezes_fetec: v && v !== 'iniciante' ? f.vezes_fetec : '' }));
                                    }}
                                >
                                    <option value="">Selecione</option>
                                    <option value="iniciante">Menos de 1 ano (primeira vez orientando)</option>
                                    <option value="1a3">De 1 a 3 anos</option>
                                    <option value="3a5">De 3 a 5 anos</option>
                                    <option value="5a10">De 5 a 10 anos</option>
                                    <option value="mais10">Mais de 10 anos</option>
                                </Select>
                            </Field>
                            {form.tempo_orientacao && form.tempo_orientacao !== 'iniciante' && (
                                <Field label="Quantas vezes você já participou da FETEC como orientador?">
                                    <Select value={form.vezes_fetec ?? ''} onChange={set('vezes_fetec')}>
                                        <option value="">Selecione</option>
                                        <option value="1a3">De 1 a 3 vezes</option>
                                        <option value="3a5">De 3 a 5 vezes</option>
                                        <option value="5a10">De 5 a 10 vezes</option>
                                        <option value="mais10">Mais de 10 vezes</option>
                                    </Select>
                                </Field>
                            )}
                            <label className="md:col-span-2 flex items-start gap-2 cursor-pointer">
                                <input type="checkbox" checked={!!form.ex_aluno_fetec} onChange={set('ex_aluno_fetec')} className="mt-1 w-5 h-5 rounded text-primary-container" />
                                <span className="text-sm text-on-surface">Ex-aluno FETEC — já estive na FETEC como aluno</span>
                            </label>
                        </div>
                    )}

                    {step === 3 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <Field label="CEP" error={err('cep')}>
                                <Input value={form.cep ?? ''} onChange={set('cep')} error={err('cep')} placeholder="00000-000" />
                            </Field>
                            <Field label="País">
                                <Select value={form.pais ?? 'BR'} onChange={set('pais')}>
                                    <option value="BR">Brasil</option>
                                    <option value="OUTRO">Outro</option>
                                </Select>
                            </Field>
                            <Field label="Estado">
                                <Input value={form.estado ?? ''} onChange={set('estado')} placeholder="MS" />
                            </Field>
                            <Field label="Cidade">
                                <Input value={form.cidade ?? ''} onChange={set('cidade')} placeholder="Campo Grande" />
                            </Field>
                            <Field label="Bairro">
                                <Input value={form.bairro ?? ''} onChange={set('bairro')} placeholder="Centro" />
                            </Field>
                            <div className="md:col-span-2">
                                <Field label="Logradouro">
                                    <Input value={form.logradouro ?? ''} onChange={set('logradouro')} placeholder="Rua, Avenida, etc." />
                                </Field>
                            </div>
                            <Field label="Número">
                                <Input value={form.numero ?? ''} onChange={set('numero')} placeholder="123" />
                            </Field>
                            <Field label="Complemento">
                                <Input value={form.complemento ?? ''} onChange={set('complemento')} placeholder="Apto, Bloco (opcional)" />
                            </Field>
                        </div>
                    )}
                </div>

                {/* Rodapé de navegação */}
                <div className="px-6 sm:px-10 py-5 border-t border-outline-variant/20 bg-surface-bright flex items-center justify-between gap-3">
                    {step > 1 ? (
                        <Button type="button" variant="outline" onClick={() => setStep((s) => s - 1)}>
                            <span className="material-symbols-outlined text-[20px]">arrow_back</span>
                            VOLTAR
                        </Button>
                    ) : (
                        <Link to="/login" className="text-sm text-primary-container font-semibold hover:underline">
                            Já tenho conta
                        </Link>
                    )}

                    {step < 3 ? (
                        <Button key="next" type="button" onClick={() => setStep((s) => s + 1)}>
                            PRÓXIMO
                            <span className="material-symbols-outlined text-[20px]">arrow_forward</span>
                        </Button>
                    ) : (
                        <Button key="submit" type="submit" variant="success" loading={loading}>
                            CRIAR CONTA
                        </Button>
                    )}
                </div>
            </form>
        </AuthCard>
    );
}
