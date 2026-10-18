import { useMemo, useRef, useState } from 'react';
import { Alert, Button, useConfirm } from './ui.jsx';
import AjudaBalao from './AjudaBalao.jsx';
import EscalaResposta from './EscalaResposta.jsx';
import VideoPreview from './VideoPreview.jsx';
import SinalizacoesProjeto from './SinalizacoesProjeto.jsx';
import {
    iniciarAvaliacaoPresencial, salvarRascunhoPresencial, concluirAvaliacaoPresencial,
} from '../lib/avaliacaoPresencial.js';

/** As chamadas do wizard (injetáveis nos testes). */
const API = {
    iniciar: (...a) => iniciarAvaliacaoPresencial(...a),
    salvar: (...a) => salvarRascunhoPresencial(...a),
    concluir: (...a) => concluirAvaliacaoPresencial(...a),
};

// Três casas, como o documento: os pesos foram escolhidos para desempatar.
const formatar = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(3).replace('.', ',');

const PILL = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary text-on-secondary',
};

const respondida = (v) => v !== undefined && v !== null && v !== '';

/** A pergunta aparece? As condicionais (2.2, 3.2, 4.2, 4.3, 14.2…) só depois do "Sim". */
const aplicavel = (p, respostas) => !p.condicao || respostas[p.condicao.pergunta] === p.condicao.valor;

const opcaoDe = (p, valor) => p.opcoes?.find((o) => String(o.valor) === String(valor));

/**
 * Pontos de uma pergunta — espelho de `RubricaPresencial::pontos()`. A
 * condicional que não se aplica vale a fração fixa do documento (zero na 3.2,
 * o peso cheio na 14.2), mas só depois de a pergunta de origem ser respondida.
 */
function pontos(p, respostas) {
    if (!aplicavel(p, respostas)) {
        return respondida(respostas[p.condicao.pergunta]) ? p.condicao.fracao_senao * p.peso : 0;
    }

    return respondida(respostas[p.chave]) ? (opcaoDe(p, respostas[p.chave])?.fracao ?? 0) * p.peso : 0;
}

/** Nota do que já foi respondido — espelho; quem calcula é o servidor. */
function notaParcial(perguntas, respostas) {
    return perguntas.reduce((soma, p) => soma + pontos(p, respostas), 0);
}

/** Sim/Não (e o "Não se aplica" da 4.3): um botão por opção. */
function RespostaOpcoes({ nome, opcoes, valor, onChange, legenda }) {
    return (
        <div className="flex flex-wrap gap-3" role="radiogroup" aria-label={legenda}>
            {opcoes.map((o) => (
                <label
                    key={o.valor}
                    className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm cursor-pointer transition-colors ${
                        valor === o.valor
                            ? 'border-primary-container bg-primary-fixed/60 text-primary-container'
                            : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant/40'
                    }`}
                >
                    <input
                        type="radio"
                        name={nome}
                        checked={valor === o.valor}
                        onChange={() => onChange(o.valor)}
                        aria-label={o.rotulo}
                        className="w-4 h-4"
                    />
                    {o.rotulo}
                </label>
            ))}
        </div>
    );
}

/** Uma pergunta: número, enunciado, balão de orientação e as opções dela. */
function Pergunta({ pergunta: p, valor, onChange }) {
    return (
        <div className="rounded-xl border border-outline-variant/40 p-4 space-y-3">
            <div className="flex items-start justify-between gap-2">
                <p className="text-sm font-semibold text-on-surface">
                    {p.numero && <span className="text-primary-container">{p.numero} </span>}
                    {p.texto} <span className="text-error">*</span>
                </p>
                <AjudaBalao texto={p.ajuda} />
            </div>
            <p className="text-xs text-on-surface-variant">
                {p.peso > 0
                    ? `Vale até ${formatar(p.peso)} da nota final.`
                    : `Não entra na nota.${p.sem_valor ? ` ${p.sem_valor}` : ''}`}
            </p>
            {p.tipo === 'escala' ? (
                <EscalaResposta nome={`resposta-${p.chave}`} escala={p.opcoes} valor={valor} onChange={onChange} legenda={p.texto} />
            ) : (
                <RespostaOpcoes nome={`resposta-${p.chave}`} opcoes={p.opcoes} valor={valor} onChange={onChange} legenda={p.texto} />
            )}
        </div>
    );
}

/** Por que a condicional sumiu do passo — e quanto ela vale assim. */
function NaoSeAplica({ pergunta: p }) {
    return (
        <p className="text-xs text-on-surface-variant rounded-lg bg-surface-variant/40 px-3 py-2">
            <strong>{p.numero}</strong> não se aplica
            {p.peso > 0 && (p.condicao.fracao_senao > 0 ? ` — vale como Sim (${formatar(p.peso)}).` : ' — vale 0.')}
        </p>
    );
}

function Campo({ label, children }) {
    if (!children || (Array.isArray(children) && children.length === 0)) return null;
    return (
        <div>
            <p className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">{label}</p>
            <div className="text-sm text-on-surface">{children}</div>
        </div>
    );
}

/** O projeto para ler antes (e durante) a visita ao estande. */
function LeituraDoProjeto({ projeto, prazo }) {
    return (
        <div className="space-y-3">
            {projeto.local?.estande && (
                <p className="text-sm font-semibold text-primary-container">
                    Estande {projeto.local.estande}{projeto.local.turno_label ? ` · ${projeto.local.turno_label}` : ''}
                    {prazo ? ` · avaliação aceita até ${prazo}` : ''}
                </p>
            )}
            <SinalizacoesProjeto sinalizacoes={projeto.sinalizacoes} />
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <Campo label="Categoria">{projeto.categoria}</Campo>
                <Campo label="Área">{projeto.area}{projeto.subarea ? ` · ${projeto.subarea}` : ''}</Campo>
                <Campo label="Instituição">{projeto.escola}</Campo>
                <Campo label="Orientador">{projeto.orientador}</Campo>
                <Campo label="Coorientador">{projeto.coorientador}</Campo>
            </div>
            <Campo label="Estudantes">{projeto.alunos?.length ? projeto.alunos.join(', ') : null}</Campo>
            <Campo label="Palavras-chave">{projeto.palavras_chave?.length ? projeto.palavras_chave.join(', ') : null}</Campo>
            <Campo label="Resumo"><p className="whitespace-pre-line mt-1">{projeto.resumo}</p></Campo>
            {projeto.link_video && (
                <Campo label="Vídeo">
                    <a href={projeto.link_video} target="_blank" rel="noreferrer" className="text-primary-container hover:underline break-all">{projeto.link_video}</a>
                    <VideoPreview url={projeto.link_video} />
                </Campo>
            )}
            {projeto.documentos?.length > 0 && (
                <Campo label="Documentos">
                    <ul className="mt-1 space-y-1">
                        {projeto.documentos.map((d) => (
                            <li key={d.id}>
                                <a href={d.download_url} className="inline-flex items-center gap-1 text-primary-container hover:underline">
                                    <span className="material-symbols-outlined text-[18px]">download</span>
                                    {d.nome_original || d.tipo_label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </Campo>
            )}
        </div>
    );
}

/**
 * Cor do atalho de uma seção no topo do wizard (Sprint 237). Só pinta a seção
 * por onde o avaliador já passou: verde se está completa, amarela se ficou
 * pergunta sem resposta. A que ele ainda não abriu fica neutra, e a aberta
 * agora tem o destaque de sempre.
 */
const CORES_ATALHO = {
    atual: 'border-primary-container bg-primary-fixed/60 text-primary-container',
    completa: 'border-secondary/50 bg-secondary-container/60 text-on-secondary-container',
    pendente: 'border-yellow-300 bg-yellow-100 text-yellow-900',
    neutra: 'border-outline-variant text-on-surface-variant hover:bg-surface-variant/40',
};

const ROTULO_SITUACAO = { completa: ' (completa)', pendente: ' (falta responder)' };

/**
 * A avaliação **presencial**, no molde da online (Sprint 171): o avaliador lê o
 * projeto, inicia e responde a rubrica do estande **um passo por seção**, com o
 * balão de orientação em cada pergunta, a nota parcial no rodapé e o rascunho a
 * qualquer momento. Os itens do estande (banner, diário de bordo…) não entram:
 * desde a Sprint 237 eles ficam só na checagem, que é da organização.
 *
 * A rubrica é a do documento de 2026 (`RubricaPresencial`): perguntas em escala
 * e de Sim/Não, condicionais que só aparecem depois do "Sim" e perguntas sem
 * valor, que são registro. Cada pergunta traz as próprias opções.
 *
 * O prazo é o fim do horário de avaliação do turno; passado ele — ou sem a
 * liberação da organização —, a avaliação abre só para leitura.
 */
export default function AvaliacaoPresencialModal({
    avaliacao: inicial, rubrica, teste = false, onAtualizado, onFechar, api = API,
}) {
    const [av, setAv] = useState(inicial);
    const [respostas, setRespostas] = useState(inicial.respostas ?? {});
    const [comentario, setComentario] = useState(inicial.comentario ?? '');
    const [passo, setPasso] = useState(0);
    const [projetoAberto, setProjetoAberto] = useState(inicial.status !== 'em_andamento');
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');
    const [aviso, setAviso] = useState('');
    const [confirm, dialogo] = useConfirm();
    const topo = useRef(null);

    // As seções pontuadas, e o parecer no fim.
    const passos = useMemo(() => {
        const secoes = rubrica?.secoes ?? [];
        return [...secoes.filter((s) => s.perguntas.length > 0), ...secoes.filter((s) => s.perguntas.length === 0)];
    }, [rubrica]);

    // As seções por onde o avaliador já passou. Retomando um rascunho, conta
    // como visitada a que já tem alguma resposta.
    const [visitados, setVisitados] = useState(() => new Set(passos
        .map((s, i) => (s.perguntas.some((p) => respondida(inicial.respostas?.[p.chave])) ? i : null))
        .filter((i) => i !== null)));

    const perguntas = passos.flatMap((s) => s.perguntas);
    // Só as que se aplicam contam: sem diário manuscrito, a 3.2 não é cobrada.
    const ativas = perguntas.filter((p) => aplicavel(p, respostas));
    const respondidas = ativas.filter((p) => respondida(respostas[p.chave])).length;
    const completa = respondidas === ativas.length;
    const total = notaParcial(perguntas, respostas);
    const notaMaxima = rubrica?.nota_maxima ?? 10;
    const secao = passos[Math.min(passo, passos.length - 1)];
    const ultimo = passo >= passos.length - 1;
    const editavel = av.pode_escrever && av.status === 'em_andamento';
    // O comitê salva fora do horário, mas só envia nele (Sprint 238).
    const podeEnviar = av.pode_enviar !== false;

    const feita = (s) => s.perguntas.filter((p) => aplicavel(p, respostas)).every((p) => respondida(respostas[p.chave]));

    const situacao = (s, i) => {
        if (i === passo) return 'atual';
        if (!visitados.has(i)) return 'neutra';
        return feita(s) ? 'completa' : 'pendente';
    };

    function irPara(i) {
        // Sair de uma seção é o que a marca como visitada.
        setVisitados((v) => (v.has(passo) ? v : new Set(v).add(passo)));
        setPasso(i);
        topo.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    }

    // A resposta de uma condicional que deixou de valer não vai junto.
    const payload = () => ({
        respostas: Object.fromEntries(Object.entries(respostas)
            .filter(([chave]) => ativas.some((p) => p.chave === chave))),
        comentario: comentario.trim() || null,
    });

    async function chamar(fn, sucesso) {
        setSalvando(true); setErro(''); setAviso('');
        try {
            const resp = await fn();
            const dados = resp.data ?? resp;
            setAv(dados);
            if (sucesso) setAviso(sucesso);
            onAtualizado?.();
            return dados;
        } catch (e) {
            const errs = e?.response?.data?.errors ?? {};
            setErro(Object.values(errs)[0]?.[0] || e?.response?.data?.message || 'Não foi possível salvar.');
            return null;
        } finally {
            setSalvando(false);
        }
    }

    async function iniciar() {
        const ok = await confirm({
            title: 'Iniciar avaliação', confirmLabel: 'Iniciar',
            message: 'Ao iniciar, este estande fica com você até o envio. Continuar?',
        });
        if (!ok) return;
        const dados = await chamar(() => api.iniciar(av.projeto.id, teste));
        if (dados) { setProjetoAberto(false); irPara(0); }
    }

    async function concluir() {
        const ok = await confirm({
            title: 'Enviar avaliação', confirmLabel: 'Enviar',
            message: `Nota final: ${formatar(total)} de ${formatar(notaMaxima)}. Depois de enviada, a avaliação não pode ser alterada. Continuar?`,
        });
        if (!ok) return;
        await chamar(() => api.concluir(av.id, payload(), teste));
    }

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <div className="flex items-start justify-between gap-3 px-5 py-4 border-b border-outline-variant/30">
                    <h3 className="font-display text-lg font-semibold text-on-surface truncate">{av.projeto.titulo}</h3>
                    <div className="flex items-center gap-2 shrink-0">
                        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${PILL[av.status] ?? 'bg-surface-variant'}`}>
                            {av.status_label}
                        </span>
                        <button type="button" onClick={onFechar} aria-label="Fechar" className="text-on-surface-variant p-1">
                            <span className="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-5 space-y-4">
                    <div className="flex items-center justify-between gap-3 border-b border-surface-variant pb-2">
                        <h4 className="font-display text-primary font-semibold">Projeto</h4>
                        <button type="button" onClick={() => setProjetoAberto((v) => !v)} aria-expanded={projetoAberto}
                            className="inline-flex items-center gap-1 text-sm font-semibold text-primary-container">
                            {projetoAberto ? 'Ocultar' : 'Mostrar'}
                            <span className="material-symbols-outlined text-[18px]">{projetoAberto ? 'expand_less' : 'expand_more'}</span>
                        </button>
                    </div>
                    {projetoAberto && <LeituraDoProjeto projeto={av.projeto} prazo={av.prazo_label} />}

                    {av.status === 'em_andamento' && secao && (
                        <section className="pt-2 space-y-3" ref={topo}>
                            <h4 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">Avaliação</h4>
                            <nav aria-label="Seções da avaliação" className="flex gap-1.5 overflow-x-auto pb-1">
                                {passos.map((s, i) => {
                                    const cor = situacao(s, i);
                                    return (
                                        <button key={s.chave} type="button" onClick={() => irPara(i)}
                                            aria-label={`Ir para ${s.titulo}${ROTULO_SITUACAO[cor] ?? ''}`}
                                            aria-current={i === passo ? 'step' : undefined}
                                            data-situacao={cor}
                                            className={`shrink-0 inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors ${CORES_ATALHO[cor]}`}>
                                            <span className="material-symbols-outlined text-[16px]">
                                                {s.perguntas.length > 0 && feita(s) ? 'check_circle' : s.icone}
                                            </span>
                                            {s.titulo}
                                        </button>
                                    );
                                })}
                            </nav>

                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-xs text-on-surface-variant">Passo {passo + 1} de {passos.length}</p>
                                    <h5 className="font-display text-lg font-semibold text-on-surface">{secao.titulo}</h5>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    {secao.perguntas.length > 0 && (
                                        <span className="text-xs text-on-surface-variant">
                                            {secao.maximo > 0
                                                ? <>vale até <strong className="text-on-surface">{formatar(secao.maximo)}</strong></>
                                                : 'não entra na nota'}
                                        </span>
                                    )}
                                    <AjudaBalao texto={secao.ajuda} />
                                </div>
                            </div>

                            <fieldset disabled={!editavel} className="space-y-3 border-0 p-0 m-0 min-w-0">
                                {secao.perguntas.map((p) => {
                                    if (aplicavel(p, respostas)) {
                                        return (
                                            <Pergunta key={p.chave} pergunta={p} valor={respostas[p.chave]}
                                                onChange={(v) => { setAviso(''); setRespostas((r) => ({ ...r, [p.chave]: v })); }} />
                                        );
                                    }
                                    // Ainda sem a resposta de origem, a condicional só fica escondida.
                                    return respondida(respostas[p.condicao.pergunta]) ? <NaoSeAplica key={p.chave} pergunta={p} /> : null;
                                })}

                                {secao.componente === 'comentarios' && (
                                    <div className="rounded-xl border border-outline-variant/40 p-4">
                                        <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor="comentario-presencial">
                                            Parecer para a equipe <span className="font-normal text-on-surface-variant/70">(opcional)</span>
                                        </label>
                                        <textarea id="comentario-presencial" rows={4} maxLength={2000} value={comentario}
                                            onChange={(e) => setComentario(e.target.value)}
                                            className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none resize-y" />
                                    </div>
                                )}
                            </fieldset>
                        </section>
                    )}

                    {av.status === 'concluida' && (
                        <section className="pt-2 space-y-3">
                            <h4 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">Avaliação enviada</h4>
                            {av.rubrica_anterior ? (
                                <Alert type="info">
                                    Esta avaliação foi enviada com a rubrica anterior ao documento de 2026: as respostas
                                    não casam com as perguntas de agora, mas a nota continua valendo.
                                </Alert>
                            ) : (
                                <>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        {(rubrica?.blocos ?? []).map((b) => (
                                            <div key={b.chave} className="rounded-xl bg-surface-variant/40 p-3 text-sm">
                                                <p className="text-xs text-on-surface-variant">{b.titulo}</p>
                                                <p className="font-semibold text-on-surface">
                                                    {formatar(notaParcial(perguntas.filter((p) => p.bloco === b.chave), av.respostas ?? {}))}
                                                    <span className="font-normal text-on-surface-variant"> de {formatar(b.maximo)}</span>
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                    {(rubrica?.secoes ?? []).filter((s) => s.perguntas.length > 0).map((s) => (
                                        <div key={s.chave} className="rounded-xl border border-outline-variant/40 p-3 text-sm">
                                            <p className="font-semibold text-on-surface">{s.titulo}</p>
                                            {s.perguntas.map((p) => (
                                                <p key={p.chave} className="text-on-surface-variant">
                                                    {p.numero} {p.texto} — <strong className="text-on-surface">
                                                        {aplicavel(p, av.respostas ?? {})
                                                            ? (opcaoDe(p, av.respostas?.[p.chave])?.rotulo ?? '—')
                                                            : 'Não se aplica'}
                                                    </strong>
                                                </p>
                                            ))}
                                        </div>
                                    ))}
                                </>
                            )}
                            {av.comentario && (
                                <div className="rounded-xl border border-outline-variant/40 p-3 text-sm">
                                    <p className="font-semibold text-on-surface">Parecer</p>
                                    <p className="text-on-surface-variant whitespace-pre-line">{av.comentario}</p>
                                </div>
                            )}
                        </section>
                    )}
                </div>

                <div className="px-5 py-4 border-t border-outline-variant/30">
                    {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
                    {aviso && <div className="mb-3"><Alert type="info">{aviso}</Alert></div>}
                    {editavel && av.envio_fechado && <div className="mb-3"><Alert type="warning">{av.envio_fechado}</Alert></div>}
                    {av.status !== 'concluida' && !av.pode_escrever && (
                        <p className="text-sm text-on-surface-variant text-right">
                            {av.status_label === 'Prazo encerrado'
                                ? 'O prazo desta avaliação acabou com o horário de avaliação do turno — leitura apenas.'
                                : 'Fora do horário de avaliação, ou sem a liberação da organização — leitura apenas.'}
                        </p>
                    )}
                    {av.pode_escrever && av.status === 'designada' && (
                        <div className="flex justify-end">
                            <Button type="button" loading={salvando} onClick={iniciar}>Iniciar avaliação</Button>
                        </div>
                    )}
                    {editavel && (
                        <div className="flex items-center justify-between gap-3 flex-wrap">
                            <p className="text-sm text-on-surface-variant">
                                Nota parcial <strong className="text-lg text-primary-container">{formatar(total)}</strong>
                                <span className="text-on-surface-variant/70"> de {formatar(notaMaxima)}</span>
                                <span className="block text-xs">
                                    {respondidas} de {perguntas.length} perguntas respondidas
                                    {!completa && ' — responda todas para enviar'}
                                </span>
                            </p>
                            <div className="flex items-center gap-3 flex-wrap">
                                <Button type="button" variant="outline" loading={salvando}
                                    onClick={() => chamar(() => api.salvar(av.id, payload(), teste), 'Rascunho salvo. Você pode continuar depois.')}>
                                    <span className="material-symbols-outlined text-[18px]">save</span>
                                    Salvar rascunho
                                </Button>
                                <Button type="button" variant="outline" disabled={passo === 0 || salvando} onClick={() => irPara(passo - 1)}>
                                    Voltar
                                </Button>
                                {ultimo ? (
                                    <Button type="button" variant="success" loading={salvando} disabled={!completa || !podeEnviar} onClick={concluir}>
                                        Enviar avaliação
                                    </Button>
                                ) : (
                                    <Button type="button" disabled={salvando} onClick={() => irPara(passo + 1)}>Avançar</Button>
                                )}
                            </div>
                        </div>
                    )}
                    {av.status === 'concluida' && (
                        <p className="text-sm text-on-surface text-right">
                            Avaliação concluída — nota <strong className="text-secondary">{formatar(av.nota)} de {formatar(notaMaxima)}</strong>.
                        </p>
                    )}
                </div>
            </div>
            {dialogo}
        </div>
    );
}
