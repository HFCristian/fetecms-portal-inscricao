import { useEffect, useMemo, useRef, useState } from 'react';
import { Button, Alert, Select, useConfirm } from './ui.jsx';
import SubareaCombobox from './SubareaCombobox.jsx';
import VideoPreview from './VideoPreview.jsx';
import EscalaResposta from './EscalaResposta.jsx';
import AjudaBalao from './AjudaBalao.jsx';
import { getAvaliacao, iniciarAvaliacao, concluirAvaliacao, salvarRascunhoAvaliacao } from '../lib/avaliacao.js';
import { loadAreas, loadSubareas, criarSubarea } from '../lib/catalogos.js';

const PILL = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary text-on-secondary',
};

/** Nota no formato pt_BR com duas casas (ex.: 6,74). */
const formatarNota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

/** Peso/teto no formato pt_BR, sem casas à toa (0,15 · 1,075 · 2). */
const formatarPeso = (valor) => String(Math.round(Number(valor) * 10000) / 10000).replace('.', ',');

// Conferência da classificação: '' = ainda não respondeu, 'sim' | 'nao'.
const CLASSIFICACAO_VAZIA = {
    area_correta: '', area_sugerida_id: '',
    subarea_correta: '', subarea_sugerida_id: '',
};

const paraResposta = (valor) => (valor === true ? 'sim' : valor === false ? 'nao' : '');
const paraBooleano = (resposta) => (resposta === 'sim' ? true : resposta === 'nao' ? false : null);

const formularioVazio = () => ({
    respostas: {},
    comentario_video: '',
    comentario_projeto: '',
    ...CLASSIFICACAO_VAZIA,
});

/** Preenche o formulário com o que já estiver gravado (inclusive rascunho). */
const formularioDe = (avaliacao) => ({
    respostas: { ...(avaliacao?.respostas ?? {}) },
    comentario_video: avaliacao?.comentario_video ?? '',
    comentario_projeto: avaliacao?.comentario_projeto ?? '',
    area_correta: paraResposta(avaliacao?.area_correta),
    area_sugerida_id: avaliacao?.area_sugerida_id ?? '',
    subarea_correta: paraResposta(avaliacao?.subarea_correta),
    subarea_sugerida_id: avaliacao?.subarea_sugerida_id ?? '',
});

/** Todas as perguntas pontuadas da rubrica, na ordem das seções. */
const perguntasDe = (rubrica) => (rubrica?.secoes ?? []).flatMap((s) => s.perguntas);

/** Fração do peso que a resposta rende: Sim/Não, ou a posição na escala. */
const fracao = (pergunta, valor, teto) =>
    pergunta.tipo === 'sim_nao' ? (valor ? 1 : 0) : Number(valor) / teto;

const respondida = (valor) => valor !== undefined && valor !== null && valor !== '';

/**
 * Nota ponderada do que já foi respondido, para o avaliador acompanhar. O
 * backend refaz a conta ao concluir — aqui é só espelho.
 */
function notaParcial(rubrica, respostas) {
    const teto = Math.max(...(rubrica?.escala ?? []).map((p) => p.valor), 1);

    return perguntasDe(rubrica).reduce((soma, p) => {
        const valor = respostas[p.chave];

        return respondida(valor) ? soma + fracao(p, valor, teto) * p.peso : soma;
    }, 0);
}

/** Quanto uma seção já rendeu nas respostas atuais. */
function pontosDaSecao(secao, rubrica, respostas) {
    return notaParcial({ ...rubrica, secoes: [secao] }, respostas);
}

/**
 * A seção já pode ser dada por respondida? As de recomendação são opcionais;
 * na classificação, quem marca "incorreta" precisa sugerir a certa.
 */
function secaoCompleta(secao, form) {
    if (secao.componente === 'classificacao') {
        return (
            form.area_correta !== ''
            && (form.area_correta === 'sim' || form.area_sugerida_id !== '')
            && (form.subarea_correta !== 'nao' || form.subarea_sugerida_id !== '')
        );
    }

    return secao.perguntas.every((p) => respondida(form.respostas[p.chave]));
}

/** Índice da primeira seção com erro de validação (-1 se não houver). */
function passoComErro(rubrica, erros) {
    const chaves = Object.keys(erros ?? {});
    if (chaves.length === 0) return -1;

    return (rubrica?.secoes ?? []).findIndex((secao) =>
        (secao.componente === 'classificacao' && chaves.some((c) => /^(area|subarea)/.test(c)))
        || secao.perguntas.some((p) => chaves.includes(`respostas.${p.chave}`))
        || (secao.comentario && chaves.includes(secao.comentario.campo)));
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

// Par de opções Sim/Não de uma pergunta pontuada (Sim vale o peso cheio).
function RespostaSimNao({ nome, valor, onChange, legenda }) {
    return (
        <div className="flex gap-4" role="radiogroup" aria-label={legenda}>
            {[[true, 'Sim'], [false, 'Não']].map(([v, rotulo]) => (
                <label
                    key={rotulo}
                    className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm cursor-pointer transition-colors ${
                        valor === v
                            ? 'border-primary-container bg-primary-fixed/60 text-primary-container'
                            : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant/40'
                    }`}
                >
                    <input
                        type="radio"
                        name={nome}
                        checked={valor === v}
                        onChange={() => onChange(v)}
                        aria-label={rotulo}
                        className="w-4 h-4"
                    />
                    {rotulo}
                </label>
            ))}
        </div>
    );
}

// Uma pergunta da rubrica: enunciado, balão de orientações e a métrica dela.
function Pergunta({ pergunta, escala, valor, onChange, erro }) {
    return (
        <div className="rounded-xl border border-outline-variant/40 p-4 space-y-3">
            <div className="flex items-start justify-between gap-2">
                <p className="text-sm font-semibold text-on-surface">
                    {pergunta.texto} <span className="text-error">*</span>
                </p>
                <AjudaBalao texto={pergunta.ajuda} />
            </div>

            <p className="text-xs text-on-surface-variant">Vale até {formatarPeso(pergunta.peso)} da nota final.</p>

            {pergunta.tipo === 'sim_nao' ? (
                <RespostaSimNao
                    nome={`resposta-${pergunta.chave}`}
                    valor={valor === undefined || valor === '' ? null : Boolean(valor)}
                    onChange={onChange}
                    legenda={pergunta.texto}
                />
            ) : (
                <EscalaResposta
                    nome={`resposta-${pergunta.chave}`}
                    escala={escala}
                    valor={valor}
                    onChange={onChange}
                    legenda={pergunta.texto}
                />
            )}

            {erro && <p className="text-xs text-error">{erro}</p>}
        </div>
    );
}

// Campo descritivo de recomendações (não vale ponto).
function Comentario({ campo, valor, onChange, erro }) {
    return (
        <div className="rounded-xl border border-outline-variant/40 p-4">
            <label className="block text-sm font-semibold text-on-surface mb-1" htmlFor={campo.campo}>
                {campo.label} <span className="font-normal text-on-surface-variant/70">(opcional)</span>
            </label>
            <textarea
                id={campo.campo}
                value={valor}
                onChange={(e) => onChange(e.target.value)}
                rows={4}
                maxLength={2000}
                placeholder={campo.placeholder}
                className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none resize-y"
            />
            {erro && <p className="text-xs text-error mt-1">{erro}</p>}
        </div>
    );
}

// Par de opções Sim/Não para conferir um campo da classificação.
function ConfereSimNao({ nome, pergunta, valor, onChange, opcional }) {
    return (
        <fieldset>
            <legend className="text-sm font-semibold text-on-surface">
                {pergunta} {opcional
                    ? <span className="font-normal text-on-surface-variant/70">(opcional)</span>
                    : <span className="text-error">*</span>}
            </legend>
            <div className="flex gap-4 mt-1.5">
                {[['sim', 'Sim, está correta'], ['nao', 'Não, está incorreta']].map(([v, rotulo]) => (
                    <label key={v} className="flex items-center gap-2 text-sm text-on-surface cursor-pointer">
                        <input
                            type="radio"
                            name={nome}
                            value={v}
                            checked={valor === v}
                            onChange={() => onChange(v)}
                            className="w-4 h-4 text-primary-container"
                        />
                        {rotulo}
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

/**
 * Conferência da classificação do projeto (a pergunta "Geral - início" da
 * rubrica, que não vale ponto). A área é obrigatória; a subárea é opcional —
 * mas quem marcar como incorreta precisa sugerir a correta. As listas são as
 * mesmas dos formulários do orientador (catálogo global).
 */
function Classificacao({ projeto, valores, areas, subareas, onChange, erros }) {
    const erro = (campo) => erros?.[campo]?.[0];
    const subareaSelecionada = subareas.find((s) => String(s.id) === String(valores.subarea_sugerida_id)) ?? null;
    // Subáreas listadas são as da área que vale para o projeto (a sugerida, se houver).
    const areaEfetiva = valores.area_sugerida_id || projeto.area_id;

    return (
        <div className="space-y-4">
            <div className="rounded-xl border border-outline-variant/40 p-4 space-y-3">
                <p className="text-sm text-on-surface-variant">
                    Área informada: <strong className="text-on-surface">{projeto.area ?? '—'}</strong>
                </p>
                <ConfereSimNao
                    nome="area_correta"
                    pergunta="A área do conhecimento está correta?"
                    valor={valores.area_correta}
                    onChange={(v) => onChange('area_correta', v)}
                />
                {erro('area_correta') && <p className="text-xs text-error">{erro('area_correta')}</p>}

                {valores.area_correta === 'nao' && (
                    <div>
                        <label className="block text-sm text-on-surface-variant mb-1" htmlFor="area-sugerida">
                            Área correta <span className="text-error">*</span>
                        </label>
                        <Select
                            id="area-sugerida"
                            value={valores.area_sugerida_id}
                            error={erro('area_sugerida_id')}
                            onChange={(e) => onChange('area_sugerida_id', e.target.value)}
                        >
                            <option value="">Selecione</option>
                            {areas
                                .filter((a) => String(a.id) !== String(projeto.area_id))
                                .map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                        </Select>
                        {erro('area_sugerida_id') && <p className="text-xs text-error mt-1">{erro('area_sugerida_id')}</p>}
                    </div>
                )}
            </div>

            <div className="rounded-xl border border-outline-variant/40 p-4 space-y-3">
                <p className="text-sm text-on-surface-variant">
                    Subárea informada: <strong className="text-on-surface">{projeto.subarea ?? '—'}</strong>
                </p>
                <ConfereSimNao
                    nome="subarea_correta"
                    pergunta="A subárea está correta?"
                    valor={valores.subarea_correta}
                    onChange={(v) => onChange('subarea_correta', v)}
                    opcional
                />

                {valores.subarea_correta === 'nao' && (
                    <div>
                        <label className="block text-sm text-on-surface-variant mb-1">
                            Subárea correta <span className="text-error">*</span>
                        </label>
                        <SubareaCombobox
                            options={subareas}
                            value={subareaSelecionada}
                            onChange={(sel) => onChange('subarea_sugerida_id', sel?.id ?? '')}
                            create={areaEfetiva ? (nome) => criarSubarea(areaEfetiva, nome) : undefined}
                            placeholder="Digite para buscar ou criar…"
                        />
                        {erro('subarea_sugerida_id') && <p className="text-xs text-error mt-1">{erro('subarea_sugerida_id')}</p>}
                    </div>
                )}
            </div>
        </div>
    );
}

// Avaliação já enviada, em leitura: respostas por seção, recomendações e classificação.
function AvaliacaoEnviada({ avaliacao, rubrica }) {
    const respostas = avaliacao.respostas ?? {};
    const rotuloDaEscala = (valor) => rubrica?.escala?.find((p) => p.valor === valor)?.rotulo;

    const resposta = (pergunta) => {
        const valor = respostas[pergunta.chave];
        if (!respondida(valor)) return '—';

        return pergunta.tipo === 'sim_nao'
            ? (valor ? 'Sim' : 'Não')
            : `${valor} — ${rotuloDaEscala(valor) ?? ''}`;
    };

    return (
        <div className="space-y-3">
            {(rubrica?.secoes ?? []).filter((s) => s.perguntas.length > 0).map((secao) => (
                <div key={secao.chave} className="rounded-xl border border-outline-variant/40 p-4 space-y-2">
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <span className="material-symbols-outlined text-primary-container text-[20px]">{secao.icone}</span>
                            <h4 className="font-semibold text-on-surface">{secao.titulo}</h4>
                        </div>
                        <span className="text-sm font-bold text-secondary shrink-0">
                            {formatarNota(pontosDaSecao(secao, rubrica, respostas))}
                            <span className="font-normal text-on-surface-variant/70">/{formatarPeso(secao.maximo)}</span>
                        </span>
                    </div>

                    {secao.perguntas.map((p) => (
                        <div key={p.chave} className="text-sm">
                            <p className="text-on-surface-variant">{p.texto}</p>
                            <p className="font-semibold text-on-surface">{resposta(p)}</p>
                        </div>
                    ))}
                </div>
            ))}

            {(rubrica?.secoes ?? []).filter((s) => s.comentario && avaliacao[s.comentario.campo]).map((secao) => (
                <div key={secao.comentario.campo} className="rounded-xl border border-outline-variant/40 p-4">
                    <p className="text-sm font-semibold text-on-surface">{secao.comentario.label}</p>
                    <p className="mt-1 text-sm text-on-surface-variant whitespace-pre-line">
                        {avaliacao[secao.comentario.campo]}
                    </p>
                </div>
            ))}

            <div className="rounded-xl border border-outline-variant/40 p-4 space-y-1 text-sm">
                <p className="font-semibold text-on-surface">Classificação</p>
                <p className="text-on-surface-variant">
                    Área:{' '}
                    {avaliacao.area_correta
                        ? 'confirmada pelo avaliador'
                        : `sugerida — ${avaliacao.area_sugerida ?? '—'}`}
                </p>
                {avaliacao.subarea_correta !== null && (
                    <p className="text-on-surface-variant">
                        Subárea:{' '}
                        {avaliacao.subarea_correta
                            ? 'confirmada pelo avaliador'
                            : `sugerida — ${avaliacao.subarea_sugerida ?? '—'}`}
                    </p>
                )}
            </div>
        </div>
    );
}

/**
 * Modal de avaliação: o avaliador lê o projeto inteiro (com o vídeo embutido),
 * inicia (sem poder cancelar) e responde à rubrica da FETECMS seção por seção,
 * salvando rascunho quando quiser. A nota final sai dos pesos, no servidor.
 */
export default function AvaliacaoModal({ avaliacaoId, teste, onFechar, onAtualizado }) {
    const [dados, setDados] = useState(null); // { avaliacao, projeto, rubrica } | false (erro)
    const [form, setForm] = useState(formularioVazio);
    const [passo, setPasso] = useState(0);
    const [projetoAberto, setProjetoAberto] = useState(true);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');
    const [aviso, setAviso] = useState(''); // confirmação de rascunho salvo
    const [erros, setErros] = useState({}); // erros de validação por campo (422)
    const [areas, setAreas] = useState([]);
    const [subareas, setSubareas] = useState([]);
    const [confirm, dialogo] = useConfirm();
    const inicioDoPasso = useRef(null);

    useEffect(() => {
        getAvaliacao(avaliacaoId, teste)
            .then((d) => { setDados(d); setForm(formularioDe(d.avaliacao)); })
            .catch(() => setDados(false));
    }, [avaliacaoId, teste]);

    // Catálogo de áreas: só faz falta enquanto a avaliação está sendo preenchida.
    useEffect(() => {
        if (dados?.avaliacao?.status !== 'em_andamento') return;
        loadAreas().then(setAreas).catch(() => setAreas([]));
    }, [dados?.avaliacao?.status]);

    // Subáreas seguem a área que vale para o projeto (a sugerida, se houver).
    const areaEfetiva = form.area_sugerida_id || dados?.projeto?.area_id || '';
    useEffect(() => {
        if (!areaEfetiva) { setSubareas([]); return; }
        loadSubareas(areaEfetiva).then(setSubareas).catch(() => setSubareas([]));
    }, [areaEfetiva]);

    function setCampo(campo, valor) {
        setAviso('');
        setForm((f) => {
            const proxima = { ...f, [campo]: valor };
            // Marcar como correta descarta a sugestão; trocar de área invalida a subárea escolhida.
            if (campo === 'area_correta' && valor === 'sim') proxima.area_sugerida_id = '';
            if (campo === 'subarea_correta' && valor === 'sim') proxima.subarea_sugerida_id = '';
            if (campo === 'area_sugerida_id') proxima.subarea_sugerida_id = '';
            return proxima;
        });
    }

    function setResposta(chave, valor) {
        setAviso('');
        setForm((f) => ({ ...f, respostas: { ...f.respostas, [chave]: valor } }));
    }

    function irPara(indice) {
        setPasso(indice);
        inicioDoPasso.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    }

    async function iniciar() {
        const ok = await confirm({
            title: 'Iniciar avaliação', confirmLabel: 'Iniciar',
            message: 'Ao iniciar, você não poderá cancelar nem trocar de projeto até concluir esta avaliação. Continuar?',
        });
        if (!ok) return;
        setSalvando(true); setErro('');
        try {
            const a = await iniciarAvaliacao(avaliacaoId, teste);
            setDados((d) => ({ ...d, avaliacao: a }));
            onAtualizado?.();
        } catch (e) {
            setErro(e?.response?.data?.message || 'Não foi possível iniciar.');
        } finally {
            setSalvando(false);
        }
    }

    async function salvarRascunho() {
        setSalvando(true); setErro(''); setErros({}); setAviso('');
        try {
            const a = await salvarRascunhoAvaliacao(avaliacaoId, payload(), teste);
            setDados((d) => ({ ...d, avaliacao: a }));
            setAviso('Rascunho salvo. Você pode continuar depois.');
            onAtualizado?.();
        } catch (e) {
            setErro(e?.response?.data?.message || 'Não foi possível salvar o rascunho.');
            mostrarErros(e?.response?.data?.errors ?? {});
        } finally {
            setSalvando(false);
        }
    }

    async function concluir() {
        const ok = await confirm({
            title: 'Enviar avaliação', confirmLabel: 'Enviar',
            message: `Nota final: ${formatarNota(total)} de ${formatarPeso(notaMaxima)}. Depois de enviada, a avaliação não pode ser alterada. Continuar?`,
        });
        if (!ok) return;

        setSalvando(true); setErro(''); setErros({}); setAviso('');
        try {
            const a = await concluirAvaliacao(avaliacaoId, payload(), teste);
            setDados((d) => ({ ...d, avaliacao: a }));
            onAtualizado?.();
        } catch (e) {
            setErro(e?.response?.data?.message || 'Não foi possível concluir.');
            mostrarErros(e?.response?.data?.errors ?? {});
        } finally {
            setSalvando(false);
        }
    }

    /** Guarda os erros e leva o avaliador à primeira seção que precisa de conserto. */
    function mostrarErros(novos) {
        setErros(novos);
        const alvo = passoComErro(rubrica, novos);
        if (alvo >= 0) irPara(alvo);
    }

    /** Respostas + recomendações + conferência da classificação, como a API espera. */
    function payload() {
        const numero = (v) => (v === '' || v === null ? null : Number(v));

        return {
            respostas: form.respostas,
            comentario_video: form.comentario_video.trim() || null,
            comentario_projeto: form.comentario_projeto.trim() || null,
            area_correta: paraBooleano(form.area_correta),
            area_sugerida_id: form.area_correta === 'nao' ? numero(form.area_sugerida_id) : null,
            subarea_correta: paraBooleano(form.subarea_correta),
            subarea_sugerida_id: form.subarea_correta === 'nao' ? numero(form.subarea_sugerida_id) : null,
        };
    }

    const av = dados && dados.avaliacao;
    const p = dados && dados.projeto;
    const rubrica = dados ? dados.rubrica : null;
    const secoes = rubrica?.secoes ?? [];
    const secao = secoes[Math.min(passo, Math.max(secoes.length - 1, 0))] ?? null;
    const notaMaxima = rubrica?.nota_maxima ?? av?.nota_maxima ?? 10;
    const perguntas = useMemo(() => perguntasDe(rubrica), [rubrica]);
    const respondidas = perguntas.filter((q) => respondida(form.respostas[q.chave])).length;
    const total = notaParcial(rubrica, form.respostas);
    const completa = secoes.every((s) => secaoCompleta(s, form));
    const ultimo = passo >= secoes.length - 1;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <div className="flex items-start justify-between gap-3 px-5 py-4 border-b border-outline-variant/30">
                    <h3 className="font-display text-lg font-semibold text-on-surface truncate">
                        {p ? p.titulo : 'Avaliação'}
                    </h3>
                    <div className="flex items-center gap-2 shrink-0">
                        {av && (
                            <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${PILL[av.status] ?? 'bg-surface-variant'}`}>
                                {av.status_label}
                            </span>
                        )}
                        <button type="button" onClick={onFechar} aria-label="Fechar" className="text-on-surface-variant p-1">
                            <span className="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <div className="flex-1 overflow-y-auto p-5 space-y-4">
                    {dados === null ? (
                        <div className="text-center py-8 text-on-surface-variant">
                            <span className="inline-block w-6 h-6 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                        </div>
                    ) : dados === false ? (
                        <Alert>Não foi possível carregar o projeto.</Alert>
                    ) : (
                        <>
                            <div className="flex items-center justify-between gap-3 border-b border-surface-variant pb-2">
                                <h4 className="font-display text-primary font-semibold">Projeto</h4>
                                <button
                                    type="button"
                                    onClick={() => setProjetoAberto((v) => !v)}
                                    aria-expanded={projetoAberto}
                                    className="inline-flex items-center gap-1 text-sm font-semibold text-primary-container"
                                >
                                    {projetoAberto ? 'Ocultar' : 'Mostrar'}
                                    <span className="material-symbols-outlined text-[18px]">
                                        {projetoAberto ? 'expand_less' : 'expand_more'}
                                    </span>
                                </button>
                            </div>

                            {projetoAberto && (
                                <>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <Campo label="Categoria">{p.categoria}</Campo>
                                        <Campo label="Área">{p.area}{p.subarea ? ` · ${p.subarea}` : ''}</Campo>
                                        <Campo label="Instituição">{p.instituicao}</Campo>
                                        <Campo label="Coorientador">{p.coorientador}</Campo>
                                        {p.continuacao && (
                                            <Campo label="Projeto de continuação">
                                                Sim{p.tempo_pesquisa_meses ? ` · ${p.tempo_pesquisa_meses} meses de pesquisa` : ''}
                                            </Campo>
                                        )}
                                    </div>
                                    <Campo label="Estudantes">{p.alunos?.length ? p.alunos.join(', ') : null}</Campo>
                                    <Campo label="Palavras-chave">
                                        {p.palavras_chave?.length ? (
                                            <div className="flex flex-wrap gap-1 mt-1">
                                                {p.palavras_chave.map((k) => (
                                                    <span key={k} className="text-xs bg-surface-variant text-on-surface-variant rounded-full px-2 py-0.5">{k}</span>
                                                ))}
                                            </div>
                                        ) : null}
                                    </Campo>
                                    <Campo label="Resumo">
                                        <p className="whitespace-pre-line mt-1">{p.resumo}</p>
                                    </Campo>
                                    {p.link_video && (
                                        <Campo label="Vídeo">
                                            <a href={p.link_video} target="_blank" rel="noreferrer" className="text-primary-container hover:underline break-all">{p.link_video}</a>
                                            {/* Assista aqui mesmo: abrir o link é opcional. */}
                                            <VideoPreview url={p.link_video} />
                                        </Campo>
                                    )}
                                    {p.documentos?.length > 0 && (
                                        <Campo label="Documentos">
                                            <ul className="mt-1 space-y-1">
                                                {p.documentos.map((d) => (
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
                                </>
                            )}
                        </>
                    )}

                    {av?.status === 'em_andamento' && secao && (
                        <section className="pt-2 space-y-3" ref={inicioDoPasso}>
                            <h4 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">
                                Avaliação
                            </h4>

                            <nav aria-label="Seções da avaliação" className="flex gap-1.5 overflow-x-auto pb-1">
                                {secoes.map((s, i) => {
                                    const feita = secaoCompleta(s, form);

                                    return (
                                        <button
                                            key={s.chave}
                                            type="button"
                                            onClick={() => irPara(i)}
                                            aria-label={`Ir para ${s.titulo}`}
                                            aria-current={i === passo ? 'step' : undefined}
                                            className={`shrink-0 inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors ${
                                                i === passo
                                                    ? 'border-primary-container bg-primary-fixed/60 text-primary-container'
                                                    : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant/40'
                                            }`}
                                        >
                                            <span className="material-symbols-outlined text-[16px]">
                                                {feita ? 'check_circle' : s.icone}
                                            </span>
                                            {s.titulo}
                                        </button>
                                    );
                                })}
                            </nav>

                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-xs text-on-surface-variant">
                                        Passo {passo + 1} de {secoes.length}
                                    </p>
                                    <h5 className="font-display text-lg font-semibold text-on-surface">{secao.titulo}</h5>
                                </div>
                                <div className="flex items-center gap-2 shrink-0">
                                    {secao.perguntas.length > 0 && (
                                        <span className="text-xs text-on-surface-variant">
                                            vale até <strong className="text-on-surface">{formatarPeso(secao.maximo)}</strong>
                                        </span>
                                    )}
                                    <AjudaBalao texto={secao.ajuda} />
                                </div>
                            </div>

                            {secao.componente === 'classificacao' && (
                                <Classificacao
                                    projeto={p}
                                    valores={form}
                                    areas={areas}
                                    subareas={subareas}
                                    erros={erros}
                                    onChange={setCampo}
                                />
                            )}

                            {secao.perguntas.map((pergunta) => (
                                <Pergunta
                                    key={pergunta.chave}
                                    pergunta={pergunta}
                                    escala={rubrica.escala}
                                    valor={form.respostas[pergunta.chave]}
                                    onChange={(v) => setResposta(pergunta.chave, v)}
                                    erro={erros?.[`respostas.${pergunta.chave}`]?.[0]}
                                />
                            ))}

                            {secao.comentario && (
                                <Comentario
                                    campo={secao.comentario}
                                    valor={form[secao.comentario.campo]}
                                    onChange={(v) => setCampo(secao.comentario.campo, v)}
                                    erro={erros?.[secao.comentario.campo]?.[0]}
                                />
                            )}
                        </section>
                    )}

                    {av?.status === 'concluida' && (
                        <section className="pt-2 space-y-3">
                            <h4 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">
                                Avaliação enviada
                            </h4>
                            <AvaliacaoEnviada avaliacao={av} rubrica={rubrica} />
                        </section>
                    )}
                </div>

                {av && (
                    <div className="px-5 py-4 border-t border-outline-variant/30">
                        {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
                        {aviso && <div className="mb-3"><Alert type="info">{aviso}</Alert></div>}
                        {av.status === 'designada' && (
                            <div className="flex justify-end">
                                <Button type="button" loading={salvando} onClick={iniciar}>Iniciar avaliação</Button>
                            </div>
                        )}
                        {av.status === 'em_andamento' && (
                            <div className="flex items-center justify-between gap-3 flex-wrap">
                                <p className="text-sm text-on-surface-variant">
                                    Nota parcial{' '}
                                    <strong className="text-lg text-primary-container">{formatarNota(total)}</strong>
                                    <span className="text-on-surface-variant/70"> de {formatarPeso(notaMaxima)}</span>
                                    <span className="block text-xs">
                                        {respondidas} de {perguntas.length} perguntas respondidas
                                        {!completa && ' — responda todas e confira a área para enviar'}
                                    </span>
                                </p>
                                <div className="flex items-center gap-3 flex-wrap">
                                    <Button type="button" variant="outline" loading={salvando} onClick={salvarRascunho}>
                                        <span className="material-symbols-outlined text-[18px]">save</span>
                                        Salvar rascunho
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={passo === 0 || salvando}
                                        onClick={() => irPara(passo - 1)}
                                    >
                                        Voltar
                                    </Button>
                                    {ultimo ? (
                                        <Button type="button" variant="success" loading={salvando} disabled={!completa} onClick={concluir}>
                                            Enviar avaliação
                                        </Button>
                                    ) : (
                                        <Button type="button" disabled={salvando} onClick={() => irPara(passo + 1)}>
                                            Avançar
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                        {av.status === 'concluida' && (
                            <p className="text-sm text-on-surface text-right">
                                Avaliação concluída — nota{' '}
                                <strong className="text-secondary">
                                    {formatarNota(av.nota)} de {formatarPeso(notaMaxima)}
                                </strong>.
                            </p>
                        )}
                    </div>
                )}
            </div>
            {dialogo}
        </div>
    );
}
