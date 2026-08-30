import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Select, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getFeedback, getDestinatariosFeedback, reenviarFalhasFeedback,
    encerrarFeedback, exportarFeedback,
} from '../lib/feedback.js';

const INTERVALO = 3000; // enquanto os convites saem, a tela acompanha

/** Um número grande com legenda — os cards do topo. */
function Numero({ valor, rotulo, sufixo = '' }) {
    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 text-center">
            <div className="text-3xl font-bold text-primary-container">{valor}{sufixo}</div>
            <div className="text-sm text-on-surface-variant mt-1">{rotulo}</div>
        </div>
    );
}

/**
 * Resultado de uma pergunta de alternativas: uma barra por opção.
 *
 * Todas as opções aparecem, inclusive as que ninguém marcou — um gráfico sem a
 * opção zerada esconde justamente a informação de que ela não convenceu.
 */
function ResultadoAlternativa({ pergunta }) {
    return (
        <div className="space-y-2">
            {pergunta.opcoes.map((o) => (
                <div key={o.opcao}>
                    <div className="flex justify-between gap-2 text-sm">
                        <span className="text-on-surface truncate">{o.opcao}</span>
                        <span className="text-on-surface-variant tabular-nums shrink-0">
                            {o.total} · {o.percentual}%
                        </span>
                    </div>
                    <div className="h-2 w-full rounded-full bg-surface-variant overflow-hidden mt-1">
                        <div className="h-full rounded-full bg-primary-container" style={{ width: `${o.percentual}%` }} />
                    </div>
                </div>
            ))}
        </div>
    );
}

/** Resultado de uma dissertativa: os textos, sem autor. */
function ResultadoDissertativa({ pergunta }) {
    if (pergunta.textos.length === 0) {
        return <p className="text-sm text-on-surface-variant">Ninguém respondeu a esta pergunta ainda.</p>;
    }

    return (
        <ul className="space-y-2 max-h-96 overflow-auto">
            {pergunta.textos.map((texto, i) => (
                <li key={i} className="text-sm text-on-surface bg-surface-variant/40 rounded-lg px-3 py-2 whitespace-pre-wrap">
                    {texto}
                </li>
            ))}
        </ul>
    );
}

/** Relatório de envio dos convites, endereço a endereço. */
function RelatorioEnvio({ id, envio, onReenviar }) {
    const [situacao, setSituacao] = useState('');
    const [lista, setLista] = useState(null);

    const carregar = useCallback(() => {
        getDestinatariosFeedback(id, situacao).then(setLista).catch(() => setLista(null));
    }, [id, situacao]);

    useEffect(() => { carregar(); }, [carregar]);

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6">
            <h2 className="font-display text-primary font-semibold mb-1">Envio dos convites</h2>
            <p className="text-sm text-on-surface-variant mb-3">
                {envio.enviado} enviado(s) · {envio.falha} falha(s) · {envio.invalido} e-mail(s) inválido(s)
                {envio.pendente > 0 && ` · ${envio.pendente} na fila`}
            </p>

            <div className="flex items-end gap-2 flex-wrap mb-3">
                <Select
                    aria-label="Filtrar por situação do envio"
                    value={situacao}
                    onChange={(e) => setSituacao(e.target.value)}
                    className="w-auto"
                >
                    <option value="">Todas as situações</option>
                    <option value="enviado">Enviados</option>
                    <option value="falha">Falhas</option>
                    <option value="invalido">E-mails inválidos</option>
                    <option value="pendente">Na fila</option>
                </Select>
                {envio.falha > 0 && (
                    <Button type="button" variant="outline" onClick={onReenviar}>
                        <span className="material-symbols-outlined text-[20px]">refresh</span>
                        Reenviar as falhas
                    </Button>
                )}
            </div>

            {lista === null ? (
                <p className="text-sm text-on-surface-variant">Carregando…</p>
            ) : lista.data.length === 0 ? (
                <p className="text-sm text-on-surface-variant">Nenhum destinatário nesta situação.</p>
            ) : (
                <ul className="divide-y divide-outline-variant/30 max-h-72 overflow-auto">
                    {lista.data.map((d) => (
                        <li key={d.id} className="py-2 flex justify-between gap-2 text-sm">
                            <span className="min-w-0">
                                <span className="text-on-surface truncate block">{d.nome}</span>
                                <span className="text-xs text-on-surface-variant truncate block">{d.email}</span>
                                {d.erro && <span className="text-xs text-error block">{d.erro}</span>}
                            </span>
                            <span className="text-xs text-on-surface-variant shrink-0">{d.status_label}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Resultados de um pedido de feedback.
 *
 * O anonimato é o que a tela precisa preservar: os números dizem quantos
 * responderam cada coisa, e as dissertativas aparecem sem autor — não há como
 * ligá-las a ninguém, porque a resposta não guarda dono.
 */
export default function AdminFeedbackDetalhe() {
    const { id } = useParams();
    const [dados, setDados] = useState(null);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');
    const [confirm, dialogo] = useConfirm();

    const carregar = useCallback(() => getFeedback(id).then(setDados).catch(() => setDados(null)), [id]);

    useEffect(() => { carregar(); }, [carregar]);

    // Enquanto os convites saem, acompanha; parado o envio, para de consultar.
    useEffect(() => {
        if (dados?.status !== 'enviando') return undefined;
        const t = setInterval(carregar, INTERVALO);
        return () => clearInterval(t);
    }, [dados?.status, carregar]);

    async function reenviar() {
        setMsg(''); setErro('');
        try {
            const resp = await reenviarFalhasFeedback(id);
            setMsg(resp.meta?.message ?? '');
            await carregar();
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível reenviar.');
        }
    }

    async function encerrar() {
        const ok = await confirm({
            title: 'Encerrar o feedback?',
            message: 'Ele sai do ar e ninguém mais responde. Os resultados continuam aqui.',
            confirmLabel: 'Encerrar',
            danger: true,
        });
        if (!ok) return;

        try {
            const resp = await encerrarFeedback(id);
            setMsg(resp.meta?.message ?? '');
            await carregar();
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível encerrar.');
        }
    }

    if (dados === null) {
        return (
            <AppShell>
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            </AppShell>
        );
    }

    const r = dados.resumo;

    return (
        <AppShell>
            <Link to="/admin/comunicacao/feedback" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Feedback
            </Link>
            <div className="flex items-center gap-2 flex-wrap mb-1">
                <h1 className="font-display text-2xl font-semibold text-primary">{dados.titulo}</h1>
                <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-primary-fixed text-primary-container">
                    {dados.status_label}
                </span>
            </div>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                {dados.publicos.join(', ')} · criado em {dados.criado_em}
                {dados.encerrado_em && ` · encerrado em ${dados.encerrado_em}`}
            </p>

            {msg && <div className="mb-4 max-w-3xl"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6 max-w-4xl">
                <Numero valor={r.convidados} rotulo="Convidados" />
                <Numero valor={r.viram} rotulo="Viram o convite" />
                <Numero valor={r.responderam} rotulo="Responderam" />
                <Numero valor={r.taxa_resposta ?? 0} sufixo="%" rotulo="Taxa de resposta" />
            </div>

            <div className="flex gap-2 flex-wrap mb-6">
                <Button type="button" variant="outline" onClick={() => exportarFeedback(id)}>
                    <span className="material-symbols-outlined text-[20px]">download</span>
                    Exportar respostas (CSV)
                </Button>
                {dados.status !== 'encerrado' && (
                    <Button type="button" variant="outline" onClick={encerrar}>
                        <span className="material-symbols-outlined text-[20px]">block</span>
                        Encerrar
                    </Button>
                )}
            </div>

            <div className="max-w-3xl">
                <RelatorioEnvio id={id} envio={r.envio} onReenviar={reenviar} />

                <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Resultados</h2>
                <p className="text-sm text-on-surface-variant mb-4">
                    As respostas são <strong>anônimas</strong> — não há como saber quem escreveu o quê.
                </p>

                {dados.perguntas.map((p, i) => (
                    <div key={p.id} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-4">
                        <h3 className="font-semibold text-on-surface mb-1">{i + 1}. {p.enunciado}</h3>
                        <p className="text-xs text-on-surface-variant mb-3">{p.respostas} resposta(s)</p>
                        {p.tipo === 'alternativa'
                            ? <ResultadoAlternativa pergunta={p} />
                            : <ResultadoDissertativa pergunta={p} />}
                    </div>
                ))}
            </div>

            {dialogo}
        </AppShell>
    );
}
