import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth, extractErrors, homeFor } from '../lib/auth.jsx';
import AuthCard from '../components/AuthCard.jsx';
import { Field, Input, CpfInput, Select, Button, Alert } from '../components/ui.jsx';
import SubareaCombobox from '../components/SubareaCombobox.jsx';
import { useCatalogos, loadSubareas } from '../lib/catalogos.js';
import { validarObrigatorios } from '../lib/validacao.js';

// Obrigatórios do avaliador (todos menos a subárea).
const AVALIADOR_OBRIGATORIOS = ['name', 'email', 'cpf', 'titulacao', 'area_id', 'password', 'password_confirmation'];

// Titulação com a situação explícita: quem está CURSANDO a pós-graduação já
// pode avaliar, não precisa ter concluído (espelha AvaliadorProfile::TITULACOES).
const TITULACOES = [
    'Especialização (em andamento)',
    'Especialização (concluída)',
    'Mestrado (em andamento)',
    'Mestrado (concluído)',
    'Doutorado (em andamento)',
    'Doutorado (concluído)',
];

export default function CadastroAvaliador() {
    const { registerAvaliador } = useAuth();
    const navigate = useNavigate();
    const catalogos = useCatalogos();
    const [form, setForm] = useState({});
    const [subareas, setSubareas] = useState([]);
    const [errors, setErrors] = useState({});
    const [alert, setAlert] = useState('');
    const [loading, setLoading] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [ciente, setCiente] = useState(false);

    const set = (name) => (e) => setForm((f) => ({ ...f, [name]: e.target.value }));
    const err = (name) => errors[name]?.[0];

    async function onAreaChange(areaId) {
        setForm((f) => ({ ...f, area_id: areaId, subarea_id: '', subarea_nome: '' }));
        setSubareas(areaId ? await loadSubareas(areaId) : []);
    }

    // Subárea (opcional): id quando existente; nome quando nova (criada na transação).
    const subareaValue = form.subarea_id
        ? (subareas.find((s) => String(s.id) === String(form.subarea_id)) ?? null)
        : (form.subarea_nome ? { id: null, nome: form.subarea_nome } : null);
    function onSubareaChange(sel) {
        setForm((f) => ({
            ...f,
            subarea_id: sel?.id ?? '',
            subarea_nome: sel && sel.id == null ? sel.nome : '',
        }));
    }

    // Clicar no botão não cadastra direto: valida os obrigatórios e, se ok,
    // abre o cartão de ciência (exclusão mútua).
    function onSubmit(e) {
        e.preventDefault();
        const faltando = validarObrigatorios(form, AVALIADOR_OBRIGATORIOS);
        if (Object.keys(faltando).length) {
            setErrors(faltando);
            setAlert('Preencha todos os campos obrigatórios.');
            return;
        }
        setAlert('');
        setErrors({});
        setCiente(false);
        setConfirming(true);
    }

    // Só executa após o usuário marcar a checkbox de ciência e clicar em "Concordo".
    async function confirmar() {
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
            setConfirming(false); // fecha o cartão para o usuário ver os erros de campo
        } finally {
            setLoading(false);
        }
    }

    return (
        <AuthCard>
            <div className="flex flex-col grow justify-center pt-8 w-full mx-auto">
                <div className="px-6 sm:px-10">
                    <h2 className="font-display text-2xl font-semibold text-on-surface mb-1">Cadastro de Avaliador</h2>
                    <p className="text-sm text-on-surface-variant mb-4">
                        Avaliadores analisam projetos submetidos. Quem é orientador (ou coorientador) não pode ser avaliador.
                    </p>
                    <div className="mb-6 flex items-start gap-2 rounded-lg bg-primary-fixed/60 border border-primary-container/30 p-3 text-sm text-on-surface">
                        <span className="material-symbols-outlined text-primary-container text-[20px]">school</span>
                        <p>
                            <strong>Quem está cursando pós-graduação já pode se cadastrar.</strong>{' '}
                            Especialização, mestrado ou doutorado <strong>em andamento</strong> habilitam a
                            avaliar — não é preciso ter concluído.
                        </p>
                    </div>

                    {alert && <div className="mb-4"><Alert>{alert}</Alert></div>}
                </div>

                <form onSubmit={onSubmit} className="px-6 py-6 sm:px-10 space-y-4 overflow-y-auto max-h-[55vh] border-t border-outline-variant/20">
                    <Field label="Nome Completo" required error={err('name')}>
                        <Input value={form.name ?? ''} onChange={set('name')} error={err('name')} />
                    </Field>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div className="md:col-span-2">
                            <Field label="E-mail" required error={err('email')}>
                                <Input type="email" value={form.email ?? ''} onChange={set('email')} error={err('email')} />
                            </Field>
                        </div>
                        <Field label="CPF" required error={err('cpf')}>
                            <CpfInput value={form.cpf ?? ''} onChange={set('cpf')} error={err('cpf')} />
                        </Field>
                        <Field
                            label="Titulação"
                            required
                            error={err('titulacao')}
                            hint="Basta estar cursando: pós-graduação em andamento já habilita."
                        >
                            <Select value={form.titulacao ?? ''} onChange={set('titulacao')} error={err('titulacao')}>
                                <option value="">Selecione</option>
                                {TITULACOES.map((t) => <option key={t} value={t}>{t}</option>)}
                            </Select>
                        </Field>
                        <div className="md:col-span-2">
                            <Field label="Área de Atuação" required error={err('area_id')}>
                                <Select value={form.area_id ?? ''} onChange={(e) => onAreaChange(e.target.value)} error={err('area_id')}>
                                    <option value="">Selecione</option>
                                    {catalogos.areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                                </Select>
                            </Field>
                        </div>
                        <div className="md:col-span-2">
                            <Field label="Subárea (preferencial para o match)" hint="Opcional. Digite para buscar; se não existir, você pode criar e ela passa a valer para todos.">
                                <SubareaCombobox
                                    options={subareas}
                                    value={subareaValue}
                                    onChange={onSubareaChange}
                                    disabled={!form.area_id}
                                    placeholder={form.area_id ? 'Digite para buscar ou criar…' : 'Escolha a área primeiro'}
                                />
                            </Field>
                        </div>
                        <Field label="Senha" required error={err('password')} hint="Mínimo de 8 caracteres.">
                            <Input type="password" value={form.password ?? ''} onChange={set('password')} error={err('password')} />
                        </Field>
                        <Field label="Confirmar Senha" required error={err('password_confirmation')}>
                            <Input type="password" value={form.password_confirmation ?? ''} onChange={set('password_confirmation')} error={err('password_confirmation')} />
                        </Field>
                    </div>

                    <Button type="submit" className="w-full">
                        <span className="material-symbols-outlined text-[20px]">verified_user</span>
                        CRIAR CONTA DE AVALIADOR
                    </Button>
                </form>
            </div>

            {/* Rodapé de navegação */}
            <div className="px-6 sm:px-10 py-5 border-t border-outline-variant/20 bg-surface-bright flex items-center justify-center gap-3">
                <p className="text-center text-sm text-on-surface-variant">
                    Já tem conta? <Link to="/login" className="font-semibold text-primary-container hover:underline">Entrar</Link>
                </p>
            </div>

            {confirming && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                    role="dialog" aria-modal="true" aria-labelledby="aviso-avaliador-titulo">
                    <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                        <div className="flex items-center gap-3">
                            <span className="material-symbols-outlined text-[28px] text-error">warning</span>
                            <h3 id="aviso-avaliador-titulo" className="font-display text-xl font-semibold text-on-surface">
                                Atenção: exclusão mútua
                            </h3>
                        </div>
                        <div className="text-sm text-on-surface-variant space-y-2">
                            <p>Ao confirmar o cadastro como <strong>avaliador</strong>, esteja ciente de que:</p>
                            <ul className="list-disc pl-5 space-y-1">
                                <li>você <strong>não poderá se cadastrar como orientador</strong> com esta conta; e</li>
                                <li>você <strong>não poderá ser cadastrado como coorientador</strong> de nenhum projeto.</li>
                            </ul>
                            <p>Isso é necessário porque o avaliador analisa projetos submetidos por terceiros.</p>
                        </div>

                        <label className="flex items-start gap-2 text-sm text-on-surface cursor-pointer select-none">
                            <input type="checkbox" checked={ciente} onChange={(e) => setCiente(e.target.checked)}
                                className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>Estou ciente e de acordo com essas condições.</span>
                        </label>

                        <div className="flex justify-end gap-3 pt-1">
                            <Button type="button" variant="outline" onClick={() => setConfirming(false)} disabled={loading}>
                                Cancelar
                            </Button>
                            <Button type="button" variant="success" onClick={confirmar} loading={loading} disabled={!ciente}>
                                Concordo
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AuthCard>
    );
}
