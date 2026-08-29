import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Field, Input, Button, Alert, useConfirm } from '../components/ui.jsx';
import { getModelosEmail, salvarModeloEmail, restaurarModeloEmail } from '../lib/admin.js';

// Mesmas classes do textarea do resto do app (o ui.jsx só exporta o Input).
const campoClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface placeholder:text-on-surface-variant focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20';

/**
 * Editor de um modelo: assunto, corpo e os botões que inserem as variáveis na
 * posição do cursor. Restaurar devolve o texto de fábrica.
 */
function Editor({ modelo, onSalvo }) {
    const [assunto, setAssunto] = useState(modelo.assunto);
    const [corpo, setCorpo] = useState(modelo.corpo);
    const [salvando, setSalvando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');
    const corpoRef = useRef(null);
    const [cursor, setCursor] = useState(null);
    const [confirmar, dialogo] = useConfirm();

    useEffect(() => { setAssunto(modelo.assunto); setCorpo(modelo.corpo); }, [modelo]);

    // Devolve o cursor ao ponto certo depois que o React redesenha o campo
    // controlado (inserir texto não move o cursor sozinho).
    useEffect(() => {
        if (cursor === null) return;
        const campo = corpoRef.current;
        if (campo) {
            campo.focus();
            campo.setSelectionRange(cursor, cursor);
        }
        setCursor(null);
    }, [cursor]);

    function inserirVariavel(chave) {
        const marcador = `{{${chave}}}`;
        const campo = corpoRef.current;
        const inicio = campo?.selectionStart ?? corpo.length;
        const fim = campo?.selectionEnd ?? corpo.length;

        setCorpo(corpo.slice(0, inicio) + marcador + corpo.slice(fim));
        setCursor(inicio + marcador.length);
    }

    async function salvar(e) {
        e.preventDefault();
        setMsg(''); setErro(''); setSalvando(true);
        try {
            onSalvo(await salvarModeloEmail(modelo.chave, { assunto, corpo }));
            setMsg('Modelo salvo. Os próximos e-mails já saem com este texto.');
        } catch (error) {
            setErro(error?.response?.data?.message ?? 'Não consegui salvar o modelo.');
        } finally {
            setSalvando(false);
        }
    }

    async function restaurar() {
        if (!await confirmar({
            title: 'Restaurar o texto padrão?',
            message: 'O texto que você escreveu será descartado e o modelo volta ao original da FETECMS.',
            confirmLabel: 'Restaurar',
        })) return;

        setMsg(''); setErro(''); setSalvando(true);
        try {
            const atualizado = await restaurarModeloEmail(modelo.chave);
            onSalvo(atualizado);
            setMsg('Modelo restaurado para o texto padrão.');
        } catch (error) {
            setErro(error?.response?.data?.message ?? 'Não consegui restaurar o modelo.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <form onSubmit={salvar} className="space-y-4 border-t border-outline-variant/40 pt-4 mt-4">
            <Alert>{erro}</Alert>
            <Alert type="info">{msg}</Alert>

            <Field label="Assunto" required>
                <Input value={assunto} onChange={(e) => setAssunto(e.target.value)} maxLength={200} />
            </Field>

            <div className="space-y-1">
                <label className="text-sm font-semibold text-on-surface">Texto <span className="text-error">*</span></label>
                <textarea
                    ref={corpoRef}
                    rows={12}
                    className={campoClass}
                    value={corpo}
                    onChange={(e) => setCorpo(e.target.value)}
                />
                <div className="flex flex-wrap items-center gap-2 pt-1">
                    <span className="text-xs text-on-surface-variant">Inserir variável:</span>
                    {modelo.variaveis.map((v) => (
                        <button
                            key={v.chave}
                            type="button"
                            title={v.descricao}
                            onClick={() => inserirVariavel(v.chave)}
                            className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold border border-outline-variant text-on-surface-variant hover:bg-primary-fixed hover:text-primary-container hover:border-primary-container transition-colors"
                        >
                            <span className="material-symbols-outlined text-[14px]">add</span>
                            {`{{${v.chave}}}`}
                        </button>
                    ))}
                </div>
                <p className="text-xs text-on-surface-variant pt-1">
                    As variáveis são trocadas no momento do envio. Uma linha que contenha só
                    {' '}{'{{codigo}}'} sai destacada no e-mail, em bloco grande.
                </p>
            </div>

            <div className="flex flex-wrap gap-3">
                <Button type="submit" loading={salvando} disabled={assunto.trim() === '' || corpo.trim() === ''}>
                    Salvar modelo
                </Button>
                {modelo.personalizado && (
                    <Button type="button" variant="outline" onClick={restaurar} disabled={salvando}>
                        Restaurar padrão
                    </Button>
                )}
            </div>

            {dialogo}
        </form>
    );
}

/**
 * Comunicação → Modelos de e-mail. Cada e-mail automático do portal (hoje a
 * confirmação de cadastro e o aviso de projeto submetido) tem o texto editável
 * aqui; sem edição, vale o padrão da FETECMS.
 */
export default function AdminModelosEmail() {
    const [modelos, setModelos] = useState(null);
    const [aberto, setAberto] = useState(null);

    useEffect(() => { getModelosEmail().then(setModelos).catch(() => setModelos([])); }, []);

    const atualizar = (novo) => setModelos((lista) => lista.map((m) => (m.chave === novo.chave ? novo : m)));

    return (
        <AppShell>
            <Link to="/admin/comunicacao" className="text-sm text-primary-container hover:text-primary inline-flex items-center gap-1 mb-3">
                <span className="material-symbols-outlined text-[18px]">chevron_left</span>
                Comunicação
            </Link>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Modelos de e-mail</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                O texto dos e-mails que o portal manda sozinho. Editar aqui vale para os próximos
                envios; restaurar devolve o texto original da FETECMS.
            </p>

            {modelos === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <div className="space-y-4 max-w-3xl">
                    {modelos.map((m) => (
                        <div key={m.chave} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <div className="flex items-start gap-3">
                                <div className="min-w-0 grow">
                                    <h2 className="font-display text-lg font-semibold text-on-surface">{m.label}</h2>
                                    <p className="text-sm text-on-surface-variant mt-1">{m.descricao}</p>
                                    <p className="text-xs text-on-surface-variant mt-2">
                                        {m.personalizado
                                            ? `Texto personalizado${m.autor_nome ? ` por ${m.autor_nome}` : ''}.`
                                            : 'Usando o texto padrão da FETECMS.'}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setAberto(aberto === m.chave ? null : m.chave)}
                                >
                                    {aberto === m.chave ? 'Fechar' : 'Editar mensagem'}
                                </Button>
                            </div>

                            {aberto === m.chave && <Editor modelo={m} onSalvo={atualizar} />}
                        </div>
                    ))}
                </div>
            )}
        </AppShell>
    );
}
