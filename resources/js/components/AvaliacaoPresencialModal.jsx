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

const formatar = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

const PILL = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary text-on-secondary',
};

const respondida = (v) => v !== undefined && v !== null && v !== '';

/** Nota do que já foi respondido — espelho; quem calcula é o servidor. */
function notaParcial(rubrica, respostas) {
    const teto = Math.max(...(rubrica?.escala ?? []).map((p) => p.valor), 1);

    return (rubrica?.secoes ?? []).flatMap((s) => s.perguntas).reduce(
        (soma, p) => (respondida(respostas[p.chave]) ? soma + (Number(respostas[p.chave]) / teto) * p.peso : soma),
        0,
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

/** Checklist dos itens da checagem: presente/ausente, sem nota. */
function Itens({ itens, marcados, onMarcar, somenteLeitura }) {
    return (
        <ul className="space-y-2">
            {itens.map((item) => (
                <li key={item.id} className="rounded-xl border border-outline-variant/40 p-3">
                    <p className="text-sm font-semibold text-on-surface">{item.nome} <span className="text-error">*</span></p>
                    {item.descricao && <p className="text-xs text-on-surface-variant">{item.descricao}</p>}
                    <div className="flex gap-3 mt-2" role="radiogroup" aria-label={item.nome}>
                        {[['presente', 'Presente'], ['ausente', 'Ausente']].map(([valor, rotulo]) => (
                            <label key={valor} className={`flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm cursor-pointer ${
                                marcados[item.id] === valor
                                    ? 'border-primary-container bg-primary-fixed/60 text-primary-container'
                                    : 'border-outline-variant text-on-surface-variant'
                            }`}>
                                <input
                                    type="radio"
                                    name={`item-${item.id}`}
                                    checked={marcados[item.id] === valor}
                                    disabled={somenteLeitura}
                                    onChange={() => onMarcar(item.id, valor)}
                                    aria-label={`${item.nome}: ${rotulo}`}
                                />
                                {rotulo}
                            </label>
                        ))}
                    </div>
                </li>
            ))}
        </ul>
    );
}

/**
 * A avaliação **presencial**, no molde da online (Sprint 171): o avaliador lê o
 * projeto, inicia e responde a rubrica do estande **um passo por seção**, com o
 * balão de orientação em cada pergunta, a nota parcial no rodapé e o rascunho a
 * qualquer momento. O passo a mais é o **checklist dos itens da checagem**
 * (banner, diário de bordo…): presente ou ausente, sem nota.
 *
 * O prazo é o fim do turno + 30 minutos; passado ele — ou sem ativação no
 * turno —, a avaliação abre só para leitura.
 */
export default function AvaliacaoPresencialModal({
    avaliacao: inicial, rubrica, itens = [], teste = false, onAtualizado, onFechar, api = API,
}) {
    const [av, setAv] = useState(inicial);
    const [respostas, setRespostas] = useState(inicial.respostas ?? {});
    const [marcados, setMarcados] = useState(inicial.itens ?? {});
    const [comentario, setComentario] = useState(inicial.comentario ?? '');
    const [passo, setPasso] = useState(0);
    const [projetoAberto, setProjetoAberto] = useState(inicial.status !== 'em_andamento');
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');
    const [aviso, setAviso] = useState('');
    const [confirm, dialogo] = useConfirm();
    const topo = useRef(null);

    // Seções pontuadas, o checklist (se houver catálogo) e o parecer no fim.
    const passos = useMemo(() => {
        const secoes = rubrica?.secoes ?? [];
        const pontuadas = secoes.filter((s) => s.perguntas.length > 0);
        const parecer = secoes.filter((s) => s.perguntas.length === 0);
        const checklist = itens.length > 0
            ? [{ chave: 'itens', titulo: 'Itens do estande', icone: 'checklist', componente: 'itens',
                ajuda: 'O que a checagem pediu em cada estande. Marque o que você encontrou na visita — não vale nota.', perguntas: [] }]
            : [];
        return [...pontuadas, ...checklist, ...parecer];
    }, [rubrica, itens]);

    const perguntas = passos.flatMap((s) => s.perguntas);
    const respondidas = perguntas.filter((p) => respondida(respostas[p.chave])).length;
    const itensFeitos = itens.every((i) => marcados[i.id]);
    const completa = respondidas === perguntas.length && itensFeitos;
    const total = notaParcial(rubrica, respostas);
    const notaMaxima = rubrica?.nota_maxima ?? 10;
    const secao = passos[Math.min(passo, passos.length - 1)];
    const ultimo = passo >= passos.length - 1;
    const editavel = av.pode_escrever && av.status === 'em_andamento';

    const feita = (s) => (s.componente === 'itens' ? itensFeitos : s.perguntas.every((p) => respondida(respostas[p.chave])));

    function irPara(i) {
        setPasso(i);
        topo.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    }

    const payload = () => ({ respostas, itens: marcados, comentario: comentario.trim() || null });

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
                                {passos.map((s, i) => (
                                    <button key={s.chave} type="button" onClick={() => irPara(i)} aria-label={`Ir para ${s.titulo}`}
                                        aria-current={i === passo ? 'step' : undefined}
                                        className={`shrink-0 inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors ${
                                            i === passo
                                                ? 'border-primary-container bg-primary-fixed/60 text-primary-container'
                                                : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant/40'
                                        }`}>
                                        <span className="material-symbols-outlined text-[16px]">
                                            {s.perguntas.length > 0 || s.componente === 'itens' ? (feita(s) ? 'check_circle' : s.icone) : s.icone}
                                        </span>
                                        {s.titulo}
                                    </button>
                                ))}
                            </nav>

                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-xs text-on-surface-variant">Passo {passo + 1} de {passos.length}</p>
                                    <h5 className="font-display text-lg font-semibold text-on-surface">{secao.titulo}</h5>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    {secao.perguntas.length > 0 && (
                                        <span className="text-xs text-on-surface-variant">
                                            vale até <strong className="text-on-surface">{formatar(secao.maximo)}</strong>
                                        </span>
                                    )}
                                    <AjudaBalao texto={secao.ajuda} />
                                </div>
                            </div>

                            <fieldset disabled={!editavel} className="space-y-3 border-0 p-0 m-0 min-w-0">
                                {secao.perguntas.map((p) => (
                                    <div key={p.chave} className="rounded-xl border border-outline-variant/40 p-4 space-y-3">
                                        <div className="flex items-start justify-between gap-2">
                                            <p className="text-sm font-semibold text-on-surface">{p.texto} <span className="text-error">*</span></p>
                                            <AjudaBalao texto={p.ajuda} />
                                        </div>
                                        <p className="text-xs text-on-surface-variant">Vale até {formatar(p.peso)} da nota final.</p>
                                        <EscalaResposta
                                            nome={`resposta-${p.chave}`}
                                            escala={rubrica.escala}
                                            valor={respostas[p.chave]}
                                            onChange={(v) => { setAviso(''); setRespostas((r) => ({ ...r, [p.chave]: v })); }}
                                            legenda={p.texto}
                                        />
                                    </div>
                                ))}

                                {secao.componente === 'itens' && (
                                    <Itens itens={itens} marcados={marcados} somenteLeitura={!editavel}
                                        onMarcar={(id, v) => { setAviso(''); setMarcados((m) => ({ ...m, [id]: v })); }} />
                                )}

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
                            {(rubrica?.secoes ?? []).filter((s) => s.perguntas.length > 0).map((s) => (
                                <div key={s.chave} className="rounded-xl border border-outline-variant/40 p-3 text-sm">
                                    <p className="font-semibold text-on-surface">{s.titulo}</p>
                                    {s.perguntas.map((p) => (
                                        <p key={p.chave} className="text-on-surface-variant">
                                            {p.texto} — <strong className="text-on-surface">
                                                {(rubrica.escala.find((e) => e.valor === Number(av.respostas?.[p.chave]))?.rotulo) ?? '—'}
                                            </strong>
                                        </p>
                                    ))}
                                </div>
                            ))}
                            {itens.length > 0 && (
                                <div className="rounded-xl border border-outline-variant/40 p-3 text-sm">
                                    <p className="font-semibold text-on-surface">Itens do estande</p>
                                    {itens.map((i) => (
                                        <p key={i.id} className="text-on-surface-variant">
                                            {i.nome} — <strong className="text-on-surface">{av.itens?.[i.id] === 'presente' ? 'Presente' : av.itens?.[i.id] === 'ausente' ? 'Ausente' : '—'}</strong>
                                        </p>
                                    ))}
                                </div>
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
                    {av.status !== 'concluida' && !av.pode_escrever && (
                        <p className="text-sm text-on-surface-variant text-right">
                            {av.status_label === 'Prazo encerrado'
                                ? 'O prazo desta avaliação acabou com o turno — leitura apenas.'
                                : 'Fora do seu turno ativado — leitura apenas.'}
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
                                    {!completa && ' — responda todas e marque os itens para enviar'}
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
                                    <Button type="button" variant="success" loading={salvando} disabled={!completa} onClick={concluir}>
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
