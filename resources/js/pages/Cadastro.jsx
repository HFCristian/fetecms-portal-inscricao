import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth, extractErrors, homeFor } from '../lib/auth.jsx';
import AuthCard from '../components/AuthCard.jsx';
import { Field, Input, DateInput, CpfInput, TelefoneInput, Select, Button, Alert } from '../components/ui.jsx';
import { listaPaises } from '../lib/paises.js';
import { MIN_IDADE, idadeEmAnos } from '../lib/idade.js';
import { validarObrigatorios } from '../lib/validacao.js';

const PAISES = listaPaises();

const STEPS = ['Dados Pessoais', 'Info. Acadêmicas', 'Endereço'];

// Campos obrigatórios por etapa. Exceções: subárea, checkboxes (pcd, ex_aluno_fetec),
// campos condicionais (vezes_fetec) e complemento.
const OBRIGATORIOS_POR_ETAPA = {
    1: ['name', 'cpf', 'data_nascimento', 'email', 'telefone', 'genero', 'camiseta', 'password', 'password_confirmation'],
    2: ['instituicao', 'tipo_instituicao', 'vinculo', 'titulacao', 'curso_formacao', 'area_conhecimento', 'tempo_orientacao'],
    3: ['cep', 'pais', 'estado', 'cidade', 'logradouro', 'numero', 'bairro'],
};

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

    // Valida os obrigatórios da etapa atual e, se ok, avança para a próxima.
    function avancar() {
        const faltando = validarObrigatorios(form, OBRIGATORIOS_POR_ETAPA[step]);
        if (Object.keys(faltando).length) {
            setErrors(faltando);
            setAlert('Preencha todos os campos obrigatórios desta etapa.');
            return;
        }
        setErrors({});
        setAlert('');
        setStep((s) => s + 1);
    }

    async function onSubmit(e) {
        e.preventDefault();
        // Trava: só efetiva o cadastro na última etapa (evita submit acidental).
        if (step < 3) {
            avancar();
            return;
        }
        // Revalida tudo: nenhum obrigatório (de qualquer etapa) pode ficar vazio.
        const faltando = {
            ...validarObrigatorios(form, OBRIGATORIOS_POR_ETAPA[1]),
            ...validarObrigatorios(form, OBRIGATORIOS_POR_ETAPA[2]),
            ...validarObrigatorios(form, OBRIGATORIOS_POR_ETAPA[3]),
        };
        if (Object.keys(faltando).length) {
            setErrors(faltando);
            setAlert('Preencha todos os campos obrigatórios.');
            const primeiro = Object.keys(faltando)[0];
            if (FIELD_STEP[primeiro]) setStep(FIELD_STEP[primeiro]);
            return;
        }
        // Idade mínima: orientador precisa ter ao menos 21 anos completos.
        const idade = idadeEmAnos(form.data_nascimento);
        if (idade !== null && idade < MIN_IDADE) {
            setErrors({ data_nascimento: [`É necessário possuir ao menos ${MIN_IDADE} anos completos para submissão.`] });
            setAlert(`É necessário possuir ao menos ${MIN_IDADE} anos completos para submissão.`);
            setStep(1);
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
                                    className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold ${done ? 'bg-secondary text-on-secondary'
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
                                <CpfInput value={form.cpf ?? ''} onChange={set('cpf')} error={err('cpf')} />
                            </Field>
                            <Field label="Data de Nascimento" required error={err('data_nascimento')}>
                                <DateInput value={form.data_nascimento ?? ''} onChange={set('data_nascimento')} error={err('data_nascimento')} />
                            </Field>
                            <div className="md:col-span-2">
                                <Field label="E-mail" required error={err('email')}>
                                    <Input type="email" value={form.email ?? ''} onChange={set('email')} error={err('email')} placeholder="nome@instituicao.edu.br" />
                                </Field>
                            </div>
                            <Field label="Telefone/WhatsApp" required error={err('telefone')}>
                                <TelefoneInput value={form.telefone ?? ''} onChange={set('telefone')} error={err('telefone')} />
                            </Field>
                            <Field label="Gênero" required error={err('genero')}>
                                <Select value={form.genero ?? ''} onChange={set('genero')} error={err('genero')}>
                                    <option value="">Selecione</option>
                                    <option value="F">Feminino</option>
                                    <option value="M">Masculino</option>
                                    <option value="NB">Não-binário</option>
                                    <option value="O">Outro</option>
                                    <option value="P">Prefiro não informar</option>
                                </Select>
                            </Field>
                            <div className="md:col-span-2">
                                <Field label="Tamanho da Camiseta" required error={err('camiseta')}>
                                    <Select value={form.camiseta ?? ''} onChange={set('camiseta')} error={err('camiseta')}>
                                        <option value="">Selecione</option>
                                        {['PP', 'P', 'M', 'G', 'GG', 'XG'].map((t) => <option key={t} value={t}>{t}</option>)}
                                    </Select>
                                </Field>
                            </div>
                            <Field label="Senha" required error={err('password')} hint="Mínimo de 8 caracteres.">
                                <Input type="password" value={form.password ?? ''} onChange={set('password')} error={err('password')} placeholder="••••••••" />
                            </Field>
                            <Field label="Confirmar Senha" required error={err('password_confirmation')}>
                                <Input type="password" value={form.password_confirmation ?? ''} onChange={set('password_confirmation')} error={err('password_confirmation')} placeholder="••••••••" />
                            </Field>
                        </div>
                    )}

                    {step === 2 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="md:col-span-2">
                                <Field label="Instituição de Ensino" required error={err('instituicao')}>
                                    <Select value={form.instituicao ?? ''} onChange={set('instituicao')} error={err('instituicao')}>
                                        <option value="">Selecione</option>
                                        {['UFMS', 'UEMS', 'IFMS', 'UCDB'].map((i) => <option key={i} value={i}>{i}</option>)}
                                    </Select>
                                </Field>
                            </div>
                            <Field label="Tipo de Instituição" required error={err('tipo_instituicao')}>
                                <Select value={form.tipo_instituicao ?? ''} onChange={set('tipo_instituicao')} error={err('tipo_instituicao')}>
                                    <option value="">Selecione</option>
                                    <option value="publica_federal">Pública Federal</option>
                                    <option value="publica_estadual">Pública Estadual</option>
                                    <option value="publica_municipal">Pública Municipal</option>
                                    <option value="particular">Particular</option>
                                </Select>
                            </Field>
                            <Field label="Vínculo Institucional" required error={err('vinculo')}>
                                <Select value={form.vinculo ?? ''} onChange={set('vinculo')} error={err('vinculo')}>
                                    <option value="">Selecione</option>
                                    <option value="efetivo">Professor Efetivo</option>
                                    <option value="substituto">Professor Substituto</option>
                                    <option value="tecnico">Técnico Administrativo</option>
                                    <option value="pesquisador">Pesquisador</option>
                                </Select>
                            </Field>
                            <Field label="Titulação" required error={err('titulacao')}>
                                <Select value={form.titulacao ?? ''} onChange={set('titulacao')} error={err('titulacao')}>
                                    <option value="">Selecione</option>
                                    <option value="graduacao">Graduação</option>
                                    <option value="tecnologo">Tecnólogo</option>
                                    <option value="especializacao">Especialização</option>
                                    <option value="mestrado">Mestrado</option>
                                    <option value="doutorado">Doutorado</option>
                                    <option value="pos_doutorado">Pós-Doutorado</option>
                                </Select>
                            </Field>
                            <Field label="Curso de Formação" required error={err('curso_formacao')}>
                                <Input value={form.curso_formacao ?? ''} onChange={set('curso_formacao')} error={err('curso_formacao')} placeholder="Ex: Ciências Biológicas" />
                            </Field>
                            <div className="md:col-span-2">
                                <Field label="Área do Conhecimento (CNPq)" required error={err('area_conhecimento')}>
                                    <Select value={form.area_conhecimento ?? ''} onChange={set('area_conhecimento')} error={err('area_conhecimento')}>
                                        <option value="">Selecione</option>
                                        {['Exatas e da Terra', 'Biológicas', 'Engenharias', 'Saúde', 'Agrárias', 'Sociais Aplicadas', 'Humanas', 'Letras e Artes'].map((a) => <option key={a} value={a}>{a}</option>)}
                                    </Select>
                                </Field>
                            </div>
                            <div className="md:col-span-2">
                                <Field label="Subárea de Atuação">
                                    <Input value={form.subarea ?? ''} onChange={set('subarea')} placeholder="Ex: Genética Molecular" />
                                </Field>
                            </div>
                            <div className="md:col-span-2">
                                <Field label="Tempo de Experiência com Orientação" required error={err('tempo_orientacao')}>
                                    <Select
                                        error={err('tempo_orientacao')}
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
                            </div>
                            <div className="md:col-span-2">
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
                            </div>
                            <label className="md:col-span-2 flex items-start gap-2 cursor-pointer">
                                <input type="checkbox" checked={!!form.ex_aluno_fetec} onChange={set('ex_aluno_fetec')} className="mt-1 rounded text-primary-container focus:ring-primary-container/20 border-outline-variant w-5 h-5 transition-all" />
                                <div className='flex flex-col'>
                                    <span
                                        class="text-base text-on-surface block group-hover:text-primary transition-colors">Ex-aluno
                                        Fetec</span>
                                    <span
                                        class="text-sm text-on-surface-variant">Já
                                        esteve na FETEC como aluno?</span>
                                </div>
                            </label>
                        </div>
                    )}

                    {step === 3 && (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="md:col-span-2">
                                <Field label="CEP" required error={err('cep')}>
                                    <Input value={form.cep ?? ''} onChange={set('cep')} error={err('cep')} placeholder="00000-000" />
                                </Field>
                            </div>
                            <Field label="País" required error={err('pais')}>
                                <Select value={form.pais ?? 'BR'} onChange={set('pais')} error={err('pais')}>
                                    {PAISES.map((p) => <option key={p.code} value={p.code}>{p.nome}</option>)}
                                </Select>
                            </Field>
                            <Field label="Estado" required error={err('estado')}>
                                <Input value={form.estado ?? ''} onChange={set('estado')} error={err('estado')} placeholder="MS" />
                            </Field>
                            <div className="md:col-span-2">
                                <Field label="Cidade" required error={err('cidade')}>
                                    <Input value={form.cidade ?? ''} onChange={set('cidade')} error={err('cidade')} placeholder="Campo Grande" />
                                </Field>
                            </div>
                            <div className="md:col-span-2">
                                <Field label="Logradouro" required error={err('logradouro')}>
                                    <Input value={form.logradouro ?? ''} onChange={set('logradouro')} error={err('logradouro')} placeholder="Rua, Avenida, etc." />
                                </Field>
                            </div>
                            <Field label="Número" required error={err('numero')}>
                                <Input value={form.numero ?? ''} onChange={set('numero')} error={err('numero')} placeholder="123" />
                            </Field>
                            <Field label="Bairro" required error={err('bairro')}>
                                <Input value={form.bairro ?? ''} onChange={set('bairro')} error={err('bairro')} placeholder="Centro" />
                            </Field>
                            <div className="md:col-span-2">
                                <Field label="Complemento">
                                    <Input value={form.complemento ?? ''} onChange={set('complemento')} placeholder="Apto, Bloco (opcional)" />
                                </Field>
                            </div>
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
                        <Button key="next" type="button" onClick={avancar}>
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
