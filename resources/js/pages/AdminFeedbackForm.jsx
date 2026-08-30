import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, Select } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getOpcoesFeedback, criarFeedback } from '../lib/feedback.js';

/**
 * Criação de um pedido de feedback: o público, o texto e as perguntas.
 *
 * Uma pergunta é **alternativa** (opções escritas à mão ou vindas de um modelo
 * pronto) ou **dissertativa** (com limite mínimo/máximo em palavras ou
 * caracteres). O modelo é só um atalho de digitação: as opções são copiadas
 * para a pergunta, então mexer no catálogo depois não altera pedido já criado.
 */
const PERGUNTA_NOVA = () => ({
    chave: Math.random().toString(36).slice(2),
    tipo: 'alternativa',
    enunciado: '',
    obrigatoria: true,
    modelo: 'satisfacao',
    opcoes: ['', ''],
    unidade: 'palavras',
    minimo: '',
    maximo: '',
});

function EditorPergunta({ pergunta, indice, modelos, maxOpcoes, erros, onMudar, onRemover, podeRemover }) {
    const erro = (campo) => erros[`perguntas.${indice}.${campo}`];
    const set = (campos) => onMudar({ ...pergunta, ...campos });
    const alternativa = pergunta.tipo === 'alternativa';
    const usaModelo = alternativa && pergunta.modelo !== '';

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-4">
            <div className="flex items-center justify-between gap-2 mb-3">
                <h3 className="font-display font-semibold text-primary">Pergunta {indice + 1}</h3>
                {podeRemover && (
                    <button
                        type="button"
                        onClick={onRemover}
                        aria-label={`Remover a pergunta ${indice + 1}`}
                        className="p-1.5 rounded-lg text-error hover:bg-error-container transition-colors"
                    >
                        <span className="material-symbols-outlined text-[20px]">delete</span>
                    </button>
                )}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="sm:col-span-2">
                    <Field label="Enunciado" error={erro('enunciado')}>
                        <Input
                            aria-label={`Enunciado da pergunta ${indice + 1}`}
                            value={pergunta.enunciado}
                            onChange={(e) => set({ enunciado: e.target.value })}
                            error={erro('enunciado')}
                        />
                    </Field>
                </div>
                <Field label="Tipo">
                    <Select
                        aria-label={`Tipo da pergunta ${indice + 1}`}
                        value={pergunta.tipo}
                        onChange={(e) => set({ tipo: e.target.value })}
                    >
                        <option value="alternativa">Alternativas</option>
                        <option value="dissertativa">Resposta dissertativa</option>
                    </Select>
                </Field>
            </div>

            {alternativa ? (
                <div className="mt-4">
                    <Field label="Alternativas" error={erro('opcoes')}>
                        <Select
                            aria-label={`Modelo de alternativas da pergunta ${indice + 1}`}
                            value={pergunta.modelo}
                            onChange={(e) => set({ modelo: e.target.value })}
                        >
                            <option value="">Escrever as minhas</option>
                            {modelos.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                        </Select>
                    </Field>

                    {usaModelo ? (
                        <p className="text-xs text-on-surface-variant mt-2">
                            {(modelos.find((m) => m.value === pergunta.modelo)?.opcoes ?? []).join(' · ')}
                        </p>
                    ) : (
                        <div className="mt-3 space-y-2">
                            {pergunta.opcoes.map((opcao, i) => (
                                <div key={i} className="flex items-center gap-2">
                                    <Input
                                        aria-label={`Alternativa ${i + 1} da pergunta ${indice + 1}`}
                                        value={opcao}
                                        onChange={(e) => set({
                                            opcoes: pergunta.opcoes.map((o, j) => (j === i ? e.target.value : o)),
                                        })}
                                    />
                                    {pergunta.opcoes.length > 2 && (
                                        <button
                                            type="button"
                                            aria-label={`Remover a alternativa ${i + 1} da pergunta ${indice + 1}`}
                                            onClick={() => set({ opcoes: pergunta.opcoes.filter((_, j) => j !== i) })}
                                            className="p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-variant transition-colors shrink-0"
                                        >
                                            <span className="material-symbols-outlined text-[18px]">close</span>
                                        </button>
                                    )}
                                </div>
                            ))}
                            {pergunta.opcoes.length < maxOpcoes && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => set({ opcoes: [...pergunta.opcoes, ''] })}
                                >
                                    Adicionar alternativa
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            ) : (
                <div className="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <Field label="Contar em">
                        <Select
                            aria-label={`Unidade do limite da pergunta ${indice + 1}`}
                            value={pergunta.unidade}
                            onChange={(e) => set({ unidade: e.target.value })}
                        >
                            <option value="palavras">Palavras</option>
                            <option value="caracteres">Caracteres</option>
                        </Select>
                    </Field>
                    <Field label="Mínimo" error={erro('minimo')} hint="Em branco, sem mínimo.">
                        <Input
                            type="number"
                            min="1"
                            aria-label={`Mínimo da pergunta ${indice + 1}`}
                            value={pergunta.minimo}
                            onChange={(e) => set({ minimo: e.target.value })}
                            error={erro('minimo')}
                        />
                    </Field>
                    <Field label="Máximo" error={erro('maximo')} hint="Em branco, sem máximo.">
                        <Input
                            type="number"
                            min="1"
                            aria-label={`Máximo da pergunta ${indice + 1}`}
                            value={pergunta.maximo}
                            onChange={(e) => set({ maximo: e.target.value })}
                            error={erro('maximo')}
                        />
                    </Field>
                </div>
            )}

            <label className="flex items-center gap-2 text-sm text-on-surface-variant mt-4 cursor-pointer">
                <input
                    type="checkbox"
                    checked={pergunta.obrigatoria}
                    onChange={(e) => set({ obrigatoria: e.target.checked })}
                    className="accent-primary-container"
                />
                Resposta obrigatória
            </label>
        </div>
    );
}

export default function AdminFeedbackForm() {
    const navigate = useNavigate();
    const [opcoes, setOpcoes] = useState(null);
    const [form, setForm] = useState({ titulo: '', descricao: '', publicos: [] });
    const [perguntas, setPerguntas] = useState([PERGUNTA_NOVA()]);
    const [errors, setErrors] = useState({});
    const [alerta, setAlerta] = useState('');
    const [salvando, setSalvando] = useState(false);

    useEffect(() => {
        getOpcoesFeedback().then(setOpcoes)
            .catch(() => setOpcoes({ publicos: [], modelos: [], max_perguntas: 20, max_opcoes: 12 }));
    }, []);

    function alternarPublico(valor) {
        setForm((f) => ({
            ...f,
            publicos: f.publicos.includes(valor)
                ? f.publicos.filter((p) => p !== valor)
                : [...f.publicos, valor],
        }));
    }

    async function salvar(ev) {
        ev.preventDefault();
        setSalvando(true); setErrors({}); setAlerta('');
        try {
            // O backend ignora o que não vale para o tipo, mas mandar limpo
            // evita que um limite esquecido numa alternativa vire erro.
            const payload = {
                ...form,
                perguntas: perguntas.map((p) => (p.tipo === 'alternativa'
                    ? {
                        tipo: p.tipo,
                        enunciado: p.enunciado,
                        obrigatoria: p.obrigatoria,
                        modelo: p.modelo || null,
                        opcoes: p.modelo ? [] : p.opcoes,
                    }
                    : {
                        tipo: p.tipo,
                        enunciado: p.enunciado,
                        obrigatoria: p.obrigatoria,
                        unidade: p.unidade,
                        minimo: p.minimo === '' ? null : Number(p.minimo),
                        maximo: p.maximo === '' ? null : Number(p.maximo),
                    })),
            };

            const resp = await criarFeedback(payload);
            navigate(`/admin/comunicacao/feedback/${resp.data.id}`);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErrors(fields ?? {});
            setAlerta(message || 'Confira os campos e tente de novo.');
        } finally {
            setSalvando(false);
        }
    }

    const maxPerguntas = opcoes?.max_perguntas ?? 20;

    return (
        <AppShell>
            <Link to="/admin/comunicacao/feedback" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Feedback
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Novo pedido de feedback</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Escolha quem você quer ouvir e monte as perguntas. Ao publicar, sai um e-mail de convite
                para cada pessoa e o questionário aparece para ela ao entrar no portal.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}

            {opcoes === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <form onSubmit={salvar} className="max-w-3xl">
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-4">
                        <Field label="Título" error={errors.titulo}>
                            <Input
                                aria-label="Título do feedback"
                                value={form.titulo}
                                onChange={(e) => setForm((f) => ({ ...f, titulo: e.target.value }))}
                                error={errors.titulo}
                            />
                        </Field>
                        <div className="mt-4">
                            <Field label="Descrição" error={errors.descricao} hint="Aparece no convite e no e-mail.">
                                <textarea
                                    aria-label="Descrição do feedback"
                                    value={form.descricao}
                                    onChange={(e) => setForm((f) => ({ ...f, descricao: e.target.value }))}
                                    rows={3}
                                    className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                                />
                            </Field>
                        </div>
                    </div>

                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-4">
                        <h2 className="font-display text-primary font-semibold mb-1">Quem você quer ouvir</h2>
                        <p className="text-sm text-on-surface-variant mb-3">
                            Os mesmos recortes da mala direta, combináveis. Quem cair em mais de um recebe
                            só um convite.
                        </p>
                        {errors.publicos && <p className="text-xs text-error mb-2">{errors.publicos}</p>}
                        <div role="group" aria-label="Públicos do feedback" className="space-y-2">
                            {opcoes.publicos.map((p) => (
                                <label key={p.value} className="flex items-start gap-2 text-sm text-on-surface cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={form.publicos.includes(p.value)}
                                        onChange={() => alternarPublico(p.value)}
                                        className="mt-0.5 accent-primary-container"
                                    />
                                    <span>
                                        {p.label}
                                        <span className="block text-xs text-on-surface-variant">{p.descricao}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </div>

                    {errors.perguntas && <div className="mb-3"><Alert>{errors.perguntas}</Alert></div>}

                    {perguntas.map((p, i) => (
                        <EditorPergunta
                            key={p.chave}
                            pergunta={p}
                            indice={i}
                            modelos={opcoes.modelos}
                            maxOpcoes={opcoes.max_opcoes ?? 12}
                            erros={errors}
                            podeRemover={perguntas.length > 1}
                            onMudar={(nova) => setPerguntas((lista) => lista.map((x, j) => (j === i ? nova : x)))}
                            onRemover={() => setPerguntas((lista) => lista.filter((_, j) => j !== i))}
                        />
                    ))}

                    <div className="flex gap-2 flex-wrap">
                        {perguntas.length < maxPerguntas && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setPerguntas((l) => [...l, PERGUNTA_NOVA()])}
                            >
                                <span className="material-symbols-outlined text-[20px]">add</span>
                                Adicionar pergunta
                            </Button>
                        )}
                        <Button type="submit" loading={salvando}>Publicar e enviar convites</Button>
                    </div>
                </form>
            )}
        </AppShell>
    );
}
