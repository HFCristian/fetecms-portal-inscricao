import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Field, Input, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    dispararMala,
    emailParecaValido,
    exportarPreviaCsv,
    getOpcoesMala,
    getPreviaMala,
    mesclarDestinatarios,
    parseCsvDestinatarios,
    parseEmailsColados,
} from '../lib/malaDireta.js';

// Mesmas classes do textarea do resto do app (o ui.jsx só exporta o Input).
const TEXTAREA =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-on-surface '
    + 'placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 '
    + 'transition-all outline-none resize-y';

const FORM_VAZIO = { nome: '', justificativa: '', solicitante: '', assunto: '', corpo: '' };

/** Ícone de cada público, para a lista não virar um paredão de checkboxes iguais. */
const ICONE_PUBLICO = {
    todos: 'groups',
    orientadores: 'school',
    avaliadores: 'fact_check',
    orientadores_rascunho: 'edit_note',
    orientadores_submetidos: 'task_alt',
    avaliadores_pendentes: 'pending_actions',
    avaliadores_concluidas: 'done_all',
};

/** Cartão clicável de um público-alvo, com a contagem que ele traz sozinho. */
function PublicoCard({ publico, marcado, total, onToggle }) {
    return (
        <button
            type="button"
            role="checkbox"
            aria-checked={marcado}
            onClick={() => onToggle(publico.value)}
            className={`text-left rounded-xl border p-3 transition-colors ${
                marcado
                    ? 'border-primary-container bg-primary-fixed/60'
                    : 'border-outline-variant hover:bg-surface-variant/50'
            }`}
        >
            <div className="flex items-start gap-2">
                <span className={`material-symbols-outlined text-[20px] ${marcado ? 'text-primary-container' : 'text-on-surface-variant'}`}>
                    {marcado ? 'check_box' : ICONE_PUBLICO[publico.value] ?? 'check_box_outline_blank'}
                </span>
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-on-surface">{publico.label}</p>
                    <p className="text-xs text-on-surface-variant">{publico.descricao}</p>
                    {typeof total === 'number' && (
                        <p className="text-xs font-semibold text-primary mt-1">
                            {total} {total === 1 ? 'pessoa' : 'pessoas'}
                        </p>
                    )}
                </div>
            </div>
        </button>
    );
}

/**
 * Composição de uma mala direta: escolhe os públicos, junta e-mails à mão ou
 * por .csv, escreve a mensagem, confere a prévia (quantos e quais recebem) e
 * dispara — com confirmação antes.
 */
export default function AdminMalaDiretaForm() {
    const navigate = useNavigate();
    const [confirmar, dialogo] = useConfirm();
    const arquivoRef = useRef(null);

    const corpoRef = useRef(null);
    // Posição em que o cursor deve ficar depois que o React redesenhar o textarea
    // (o campo é controlado: inserir texto não move o cursor sozinho).
    const [cursorCorpo, setCursorCorpo] = useState(null);

    const [opcoes, setOpcoes] = useState(null);
    const [publicos, setPublicos] = useState([]);
    const [personalizados, setPersonalizados] = useState([]);
    const [texto, setTexto] = useState('');
    const [form, setForm] = useState(FORM_VAZIO);

    const [previa, setPrevia] = useState(null);
    const [carregandoPrevia, setCarregandoPrevia] = useState(false);
    const [listando, setListando] = useState(false);
    const [paginaLista, setPaginaLista] = useState(1);

    const [erros, setErros] = useState({});
    const [alert, setAlert] = useState('');
    const [aviso, setAviso] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [exportando, setExportando] = useState(false);

    const criterio = useMemo(
        () => ({ publicos, destinatarios: personalizados }),
        [publicos, personalizados],
    );
    const temCriterio = publicos.length > 0 || personalizados.length > 0;

    useEffect(() => {
        getOpcoesMala().then(setOpcoes).catch((e) => setAlert(extractErrors(e).message));
    }, []);

    // Prévia com debounce: cada clique num público mudaria a contagem.
    useEffect(() => {
        if (!temCriterio) { setPrevia(null); return undefined; }
        setCarregandoPrevia(true);
        const t = setTimeout(() => {
            getPreviaMala({ ...criterio, pagina: paginaLista, por_pagina: 25 })
                .then(setPrevia)
                .catch((e) => { setPrevia(null); setAlert(extractErrors(e).message); })
                .finally(() => setCarregandoPrevia(false));
        }, 400);
        return () => { clearTimeout(t); setCarregandoPrevia(false); };
    }, [criterio, temCriterio, paginaLista]);

    /** Insere a variável onde o cursor está (ou no fim, se o campo nunca teve foco). */
    function inserirVariavel(chave) {
        const marcador = `{{${chave}}}`;
        const campo = corpoRef.current;
        const corpo = form.corpo ?? '';
        const inicio = campo?.selectionStart ?? corpo.length;
        const fim = campo?.selectionEnd ?? corpo.length;

        setForm({ ...form, corpo: corpo.slice(0, inicio) + marcador + corpo.slice(fim) });
        setCursorCorpo(inicio + marcador.length);
    }

    useEffect(() => {
        if (cursorCorpo === null) return;
        const campo = corpoRef.current;
        if (campo) {
            campo.focus();
            campo.setSelectionRange(cursorCorpo, cursorCorpo);
        }
        setCursorCorpo(null);
    }, [cursorCorpo]);

    const alternarPublico = useCallback((valor) => {
        setPaginaLista(1);
        setPublicos((atuais) => (
            atuais.includes(valor) ? atuais.filter((p) => p !== valor) : [...atuais, valor]
        ));
    }, []);

    function adicionarColados() {
        const novos = parseEmailsColados(texto);
        if (novos.length === 0) return;
        setPersonalizados((atuais) => mesclarDestinatarios(atuais, novos));
        setTexto('');
        setPaginaLista(1);
        setAviso(`${novos.length} ${novos.length === 1 ? 'e-mail adicionado' : 'e-mails adicionados'} à lista.`);
    }

    async function importarCsv(evento) {
        const arquivo = evento.target.files?.[0];
        if (!arquivo) return;
        setAlert('');
        try {
            const { destinatarios, ignoradas } = parseCsvDestinatarios(await arquivo.text());
            if (destinatarios.length === 0) {
                setAlert('Não encontrei e-mails no arquivo. Use as colunas "email" e "nome".');
            } else {
                setPersonalizados((atuais) => mesclarDestinatarios(atuais, destinatarios));
                setPaginaLista(1);
                setAviso(
                    `${destinatarios.length} ${destinatarios.length === 1 ? 'e-mail importado' : 'e-mails importados'} de ${arquivo.name}`
                    + (ignoradas > 0 ? ` · ${ignoradas} linha(s) sem e-mail ignorada(s).` : '.'),
                );
            }
        } catch {
            setAlert('Não consegui ler o arquivo. Envie um .csv em texto puro.');
        } finally {
            if (arquivoRef.current) arquivoRef.current.value = '';
        }
    }

    function removerPersonalizado(email) {
        setPersonalizados((atuais) => atuais.filter((d) => d.email !== email));
        setPaginaLista(1);
    }

    async function exportar() {
        setAlert('');
        setExportando(true);
        try {
            await exportarPreviaCsv(criterio);
        } catch {
            setAlert('Não foi possível gerar o CSV. Tente novamente.');
        } finally {
            setExportando(false);
        }
    }

    async function enviar(evento) {
        evento.preventDefault();
        setAlert('');
        setErros({});

        const validos = previa?.meta?.validos ?? 0;
        if (validos === 0) {
            setAlert('Nenhum destinatário válido: escolha um público ou corrija a lista personalizada.');
            return;
        }

        const invalidos = previa?.meta?.invalidos ?? 0;
        const ok = await confirmar({
            title: 'Confirmar o envio',
            message: [
                `Assunto: ${form.assunto || '(sem assunto)'}`,
                '',
                form.corpo || '(sem texto)',
                '',
                `Destinatários: ${validos}${invalidos > 0 ? ` (${invalidos} e-mail(s) inválido(s) serão ignorados)` : ''}.`,
                'O envio começa agora e não pode ser desfeito.',
            ].join('\n'),
            confirmLabel: 'Enviar agora',
        });
        if (!ok) return;

        setEnviando(true);
        try {
            const mala = await dispararMala({ ...form, ...criterio });
            navigate(`/admin/mala-direta/${mala.id}`);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErros(fields);
            setAlert(message);
            setEnviando(false);
        }
    }

    const meta = previa?.meta;
    const listaPrevia = previa?.data ?? [];

    return (
        <AppShell>
            <div className="mb-6">
                <Link to="/admin/mala-direta" className="text-sm font-semibold text-primary hover:underline inline-flex items-center gap-1">
                    <span className="material-symbols-outlined text-[18px]">arrow_back</span>
                    Voltar para as mensagens
                </Link>
                <h1 className="font-display text-2xl font-semibold text-primary mt-2 mb-1">Nova mala direta</h1>
                <p className="text-on-surface-variant max-w-3xl">
                    Escolha quem recebe, escreva a mensagem e confira a prévia antes de disparar.
                </p>
            </div>

            {alert && <div className="mb-4 max-w-4xl"><Alert>{alert}</Alert></div>}
            {aviso && <div className="mb-4 max-w-4xl"><Alert type="info">{aviso}</Alert></div>}

            <form onSubmit={enviar} className="max-w-4xl space-y-6">
                {/* 1. Público-alvo */}
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                    <h2 className="font-display text-lg font-semibold text-on-surface mb-1">1. Quem vai receber</h2>
                    <p className="text-sm text-on-surface-variant mb-4">
                        Pode marcar mais de um: quem aparece em dois grupos recebe uma vez só.
                        Contas inativas, de teste e de administrador ficam de fora.
                    </p>
                    <div className="grid sm:grid-cols-2 gap-2">
                        {(opcoes?.publicos ?? []).map((publico) => (
                            <PublicoCard
                                key={publico.value}
                                publico={publico}
                                marcado={publicos.includes(publico.value)}
                                total={meta?.por_publico?.[publico.value]}
                                onToggle={alternarPublico}
                            />
                        ))}
                    </div>
                    {erros.publicos && <p className="text-sm text-error mt-2">{erros.publicos[0]}</p>}
                </section>

                {/* 2. Lista personalizada */}
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                    <h2 className="font-display text-lg font-semibold text-on-surface mb-1">2. E-mails avulsos (opcional)</h2>
                    <p className="text-sm text-on-surface-variant mb-4">
                        Digite os endereços (um por linha ou separados por vírgula) ou importe um
                        <strong> .csv com as colunas <code>email</code> e <code>nome</code></strong>.
                    </p>
                    <div className="flex flex-col sm:flex-row gap-3">
                        <div className="flex-1">
                            <Field label="Endereços">
                                <textarea
                                    rows={3}
                                    className={TEXTAREA}
                                    placeholder={'ana@escola.test\nBeto Lima <beto@escola.test>'}
                                    value={texto}
                                    onChange={(e) => setTexto(e.target.value)}
                                />
                            </Field>
                            <Button type="button" variant="outline" onClick={adicionarColados} disabled={texto.trim() === ''}>
                                <span className="material-symbols-outlined text-[20px]">add</span>
                                Adicionar à lista
                            </Button>
                        </div>
                        <div className="sm:w-56">
                            <Field label="Importar .csv">
                                <input
                                    ref={arquivoRef}
                                    type="file"
                                    accept=".csv,text/csv"
                                    aria-label="Importar arquivo CSV"
                                    onChange={importarCsv}
                                    className="block w-full text-sm text-on-surface-variant file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary-fixed file:text-primary-container hover:file:bg-primary-fixed/70"
                                />
                            </Field>
                        </div>
                    </div>

                    {personalizados.length > 0 && (
                        <div className="mt-3">
                            <div className="flex items-center justify-between gap-2 mb-2">
                                <p className="text-xs font-semibold text-on-surface-variant">
                                    {personalizados.length} na lista personalizada
                                </p>
                                <button type="button" onClick={() => setPersonalizados([])} className="text-xs font-semibold text-error hover:underline">
                                    Limpar lista
                                </button>
                            </div>
                            <div className="flex flex-wrap gap-2 max-h-40 overflow-y-auto">
                                {personalizados.map((d) => (
                                    <span
                                        key={d.email}
                                        className={`inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full ${
                                            emailParecaValido(d.email)
                                                ? 'bg-surface-variant text-on-surface-variant'
                                                : 'bg-error-container text-on-error-container'
                                        }`}
                                    >
                                        {d.nome ? `${d.nome} · ` : ''}{d.email}
                                        <button
                                            type="button"
                                            aria-label={`Remover ${d.email}`}
                                            onClick={() => removerPersonalizado(d.email)}
                                            className="material-symbols-outlined text-[14px] hover:text-error"
                                        >
                                            close
                                        </button>
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
                </section>

                {/* 3. Mensagem */}
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 space-y-4">
                    <div>
                        <h2 className="font-display text-lg font-semibold text-on-surface mb-1">3. A mensagem</h2>
                        <p className="text-sm text-on-surface-variant">
                            Os botões abaixo do texto inserem dados de quem recebe no ponto onde o
                            cursor estiver. Sem nome no cadastro, o tratamento vira “participante”.
                        </p>
                    </div>
                    <Field label="Nome da mala" required error={erros.nome?.[0]}>
                        <Input
                            value={form.nome}
                            error={erros.nome}
                            maxLength={120}
                            placeholder="Ex.: Lembrete do prazo de submissão"
                            onChange={(e) => setForm({ ...form, nome: e.target.value })}
                        />
                    </Field>
                    <Field label="Justificativa de envio" required error={erros.justificativa?.[0]} hint="Fica só no registro interno — o destinatário não vê.">
                        <textarea
                            rows={2}
                            maxLength={2000}
                            className={TEXTAREA}
                            placeholder="Por que este comunicado precisa ser enviado?"
                            value={form.justificativa}
                            onChange={(e) => setForm({ ...form, justificativa: e.target.value })}
                        />
                    </Field>
                    <Field label="Solicitante de envio" error={erros.solicitante?.[0]} hint="Opcional. Quem pediu o disparo (também só no registro interno).">
                        <Input
                            value={form.solicitante}
                            error={erros.solicitante}
                            maxLength={160}
                            placeholder="Ex.: Coordenação da FETECMS"
                            onChange={(e) => setForm({ ...form, solicitante: e.target.value })}
                        />
                    </Field>
                    <Field label="Assunto da mensagem" required error={erros.assunto?.[0]}>
                        <Input
                            value={form.assunto}
                            error={erros.assunto}
                            maxLength={200}
                            placeholder="O que aparece na caixa de entrada"
                            onChange={(e) => setForm({ ...form, assunto: e.target.value })}
                        />
                    </Field>
                    <Field label="Texto da mensagem" required error={erros.corpo?.[0]}>
                        <textarea
                            ref={corpoRef}
                            rows={10}
                            maxLength={20000}
                            className={TEXTAREA}
                            placeholder={'Olá, {{nome}}!\n\nEscreva aqui o comunicado.'}
                            value={form.corpo}
                            onChange={(e) => setForm({ ...form, corpo: e.target.value })}
                        />
                        <div className="flex flex-wrap items-center gap-2 pt-1">
                            <span className="text-xs text-on-surface-variant">Inserir variável:</span>
                            {(opcoes?.variaveis ?? []).map((variavel) => (
                                <button
                                    key={variavel.chave}
                                    type="button"
                                    title={`${variavel.rotulo} — ${variavel.descricao}`}
                                    onClick={() => inserirVariavel(variavel.chave)}
                                    className="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold border border-outline-variant text-on-surface-variant hover:bg-primary-fixed hover:text-primary-container hover:border-primary-container transition-colors"
                                >
                                    <span className="material-symbols-outlined text-[14px]">add</span>
                                    {`{{${variavel.chave}}}`}
                                </button>
                            ))}
                        </div>
                    </Field>
                </section>

                {/* 4. Prévia e disparo */}
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                    <h2 className="font-display text-lg font-semibold text-on-surface mb-3">4. Confira e envie</h2>

                    {!temCriterio ? (
                        <p className="text-sm text-on-surface-variant">
                            Escolha ao menos um público ou informe e-mails para ver quantas pessoas recebem.
                        </p>
                    ) : (
                        <>
                            <div className="flex flex-wrap items-center gap-3 mb-3">
                                <div className="rounded-xl bg-primary-fixed px-4 py-3">
                                    <p className="text-2xl font-display font-semibold text-primary-container leading-none">
                                        {carregandoPrevia && !meta ? '…' : (meta?.validos ?? 0)}
                                    </p>
                                    <p className="text-xs text-primary-container font-semibold mt-1">
                                        {(meta?.validos ?? 0) === 1 ? 'e-mail será enviado' : 'e-mails serão enviados'}
                                    </p>
                                </div>
                                {(meta?.invalidos ?? 0) > 0 && (
                                    <div className="rounded-xl bg-error-container px-4 py-3">
                                        <p className="text-2xl font-display font-semibold text-on-error-container leading-none">{meta.invalidos}</p>
                                        <p className="text-xs text-on-error-container font-semibold mt-1">
                                            inválido(s) — não serão enviados
                                        </p>
                                    </div>
                                )}
                                <div className="flex gap-2 ml-auto">
                                    <Button type="button" variant="outline" onClick={() => setListando((v) => !v)}>
                                        <span className="material-symbols-outlined text-[20px]">{listando ? 'visibility_off' : 'list'}</span>
                                        {listando ? 'Ocultar lista' : 'Listar e-mails'}
                                    </Button>
                                    <Button type="button" variant="outline" loading={exportando} onClick={exportar}>
                                        <span className="material-symbols-outlined text-[20px]">download</span>
                                        Exportar CSV
                                    </Button>
                                </div>
                            </div>

                            {listando && (
                                <div className="border border-outline-variant rounded-lg overflow-x-auto mb-3">
                                    <table className="w-full text-sm">
                                        <thead className="bg-surface-variant/60 text-on-surface-variant">
                                            <tr>
                                                <th className="text-left font-semibold px-3 py-2">Nome</th>
                                                <th className="text-left font-semibold px-3 py-2">E-mail</th>
                                                <th className="text-left font-semibold px-3 py-2">Origem</th>
                                                <th className="text-right font-semibold px-3 py-2">Projetos</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-outline-variant/40">
                                            {listaPrevia.map((d) => (
                                                <tr key={d.email} className={d.status === 'invalido' ? 'bg-error-container/30' : ''}>
                                                    <td className="px-3 py-2 text-on-surface">{d.nome || '—'}</td>
                                                    <td className="px-3 py-2 text-on-surface-variant">
                                                        {d.email}
                                                        {d.status === 'invalido' && (
                                                            <span className="block text-xs text-error font-semibold">{d.erro}</span>
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-2 text-xs text-on-surface-variant">
                                                        {(d.origens ?? []).length} origem(ns)
                                                    </td>
                                                    <td className="px-3 py-2 text-right text-on-surface-variant">{d.projetos_total}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {meta && meta.ultima_pagina > 1 && (
                                        <div className="flex items-center justify-between gap-3 p-3 border-t border-outline-variant">
                                            <span className="text-xs text-on-surface-variant">Página {meta.pagina_atual} de {meta.ultima_pagina}</span>
                                            <div className="flex gap-2">
                                                <Button type="button" variant="outline" disabled={meta.pagina_atual <= 1} onClick={() => setPaginaLista((p) => Math.max(1, p - 1))}>Anterior</Button>
                                                <Button type="button" variant="outline" disabled={meta.pagina_atual >= meta.ultima_pagina} onClick={() => setPaginaLista((p) => p + 1)}>Próxima</Button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </>
                    )}

                    <div className="flex justify-end gap-3 pt-2">
                        <Link to="/admin/mala-direta">
                            <Button type="button" variant="outline">Cancelar</Button>
                        </Link>
                        <Button type="submit" loading={enviando} disabled={!temCriterio || (meta?.validos ?? 0) === 0}>
                            <span className="material-symbols-outlined text-[20px]">send</span>
                            Enviar mensagem
                        </Button>
                    </div>
                </section>
            </form>
            {dialogo}
        </AppShell>
    );
}
