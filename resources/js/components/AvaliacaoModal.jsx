import { useEffect, useState } from 'react';
import { Button, Alert, Select, useConfirm } from './ui.jsx';
import SubareaCombobox from './SubareaCombobox.jsx';
import { getAvaliacao, iniciarAvaliacao, concluirAvaliacao, salvarRascunhoAvaliacao } from '../lib/avaliacao.js';
import { loadAreas, loadSubareas, criarSubarea } from '../lib/catalogos.js';

const PILL = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary text-on-secondary',
};

// Rubrica: cada quesito vale de 0 a 10 (obrigatório) e aceita sugestões e
// comentários (opcional). A nota final é a soma dos três, calculada no backend.
const QUESITOS = [
    { key: 'video', titulo: 'Vídeo de apresentação', icon: 'movie' },
    { key: 'resumo', titulo: 'Resumo do projeto', icon: 'description' },
    { key: 'pesquisa', titulo: 'Projeto de pesquisa', icon: 'science' },
];

const NOTA_MAXIMA_QUESITO = 10;

// Conferência da classificação: '' = ainda não respondeu, 'sim' | 'nao'.
const CLASSIFICACAO_VAZIA = {
    area_correta: '', area_sugerida_id: '',
    subarea_correta: '', subarea_sugerida_id: '',
};

const paraResposta = (valor) => (valor === true ? 'sim' : valor === false ? 'nao' : '');
const paraBooleano = (resposta) => (resposta === 'sim' ? true : resposta === 'nao' ? false : null);

const rubricaVazia = () => ({
    ...CLASSIFICACAO_VAZIA,
    ...Object.fromEntries(QUESITOS.flatMap((q) => [[`nota_${q.key}`, ''], [`comentario_${q.key}`, '']])),
});

/** Preenche o formulário com o que já estiver gravado (inclusive rascunho). */
const rubricaDe = (avaliacao) => ({
    area_correta: paraResposta(avaliacao?.area_correta),
    area_sugerida_id: avaliacao?.area_sugerida_id ?? '',
    subarea_correta: paraResposta(avaliacao?.subarea_correta),
    subarea_sugerida_id: avaliacao?.subarea_sugerida_id ?? '',
    ...Object.fromEntries(
        QUESITOS.flatMap((q) => [
            [`nota_${q.key}`, avaliacao?.[`nota_${q.key}`] ?? ''],
            [`comentario_${q.key}`, avaliacao?.[`comentario_${q.key}`] ?? ''],
        ]),
    ),
});

function Campo({ label, children }) {
    if (!children || (Array.isArray(children) && children.length === 0)) return null;
    return (
        <div>
            <p className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">{label}</p>
            <div className="text-sm text-on-surface">{children}</div>
        </div>
    );
}

// Um quesito da rubrica: nota obrigatória de 0 a 10 + comentário opcional.
function Quesito({ quesito, nota, comentario, onNota, onComentario, erros }) {
    const erroNota = erros?.[`nota_${quesito.key}`]?.[0];

    return (
        <div className="rounded-xl border border-outline-variant/40 p-4 space-y-3">
            <div className="flex items-center gap-2">
                <span className="material-symbols-outlined text-primary-container text-[20px]">{quesito.icon}</span>
                <h4 className="font-semibold text-on-surface">{quesito.titulo}</h4>
            </div>

            <div className="flex items-center gap-3">
                <label className="text-sm text-on-surface-variant" htmlFor={`nota-${quesito.key}`}>
                    Nota (0 a {NOTA_MAXIMA_QUESITO}) <span className="text-error">*</span>
                </label>
                <div className="w-24">
                    <Select
                        id={`nota-${quesito.key}`}
                        value={nota}
                        error={erroNota}
                        onChange={(e) => onNota(e.target.value)}
                    >
                        <option value="">—</option>
                        {Array.from({ length: NOTA_MAXIMA_QUESITO + 1 }, (_, n) => (
                            <option key={n} value={n}>{n}</option>
                        ))}
                    </Select>
                </div>
            </div>
            {erroNota && <p className="text-xs text-error">{erroNota}</p>}

            <div>
                <label className="block text-sm text-on-surface-variant mb-1" htmlFor={`comentario-${quesito.key}`}>
                    Sugestões e comentários <span className="text-on-surface-variant/70">(opcional)</span>
                </label>
                <textarea
                    id={`comentario-${quesito.key}`}
                    value={comentario}
                    onChange={(e) => onComentario(e.target.value)}
                    rows={3}
                    maxLength={2000}
                    className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2.5 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none resize-y"
                    placeholder={`O que o time pode melhorar em "${quesito.titulo.toLowerCase()}"?`}
                />
            </div>
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
 * Conferência da classificação do projeto. A área é obrigatória; a subárea é
 * opcional — mas quem marcar como incorreta precisa sugerir a correta. As
 * listas são as mesmas dos formulários do orientador (catálogo global).
 */
function Classificacao({ projeto, valores, areas, subareas, onChange, erros }) {
    const erro = (campo) => erros?.[campo]?.[0];
    const subareaSelecionada = subareas.find((s) => String(s.id) === String(valores.subarea_sugerida_id)) ?? null;
    // Subáreas listadas são as da área que vale para o projeto (a sugerida, se houver).
    const areaEfetiva = valores.area_sugerida_id || projeto.area_id;

    return (
        <section className="pt-2 space-y-4">
            <h4 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">
                Classificação do projeto
            </h4>

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
        </section>
    );
}

// Rubrica já enviada, em leitura (avaliação concluída).
function RubricaConcluida({ avaliacao }) {
    return (
        <div className="space-y-3">
            {QUESITOS.map((q) => (
                <div key={q.key} className="rounded-xl border border-outline-variant/40 p-4">
                    <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <span className="material-symbols-outlined text-primary-container text-[20px]">{q.icon}</span>
                            <h4 className="font-semibold text-on-surface">{q.titulo}</h4>
                        </div>
                        <span className="text-lg font-bold text-secondary shrink-0">
                            {avaliacao[`nota_${q.key}`]}/{NOTA_MAXIMA_QUESITO}
                        </span>
                    </div>
                    {avaliacao[`comentario_${q.key}`] && (
                        <p className="mt-2 text-sm text-on-surface-variant whitespace-pre-line">
                            {avaliacao[`comentario_${q.key}`]}
                        </p>
                    )}
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

// Modal de avaliação: lê o projeto inteiro, inicia (sem cancelar) e conclui
// preenchendo a rubrica (3 quesitos de 0 a 10 + comentários), nota final 0–30.
export default function AvaliacaoModal({ avaliacaoId, teste, onFechar, onAtualizado }) {
    const [dados, setDados] = useState(null); // { avaliacao, projeto } | false (erro)
    const [rubrica, setRubrica] = useState(rubricaVazia);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');
    const [aviso, setAviso] = useState(''); // confirmação de rascunho salvo
    const [erros, setErros] = useState({}); // erros de validação por campo (422)
    const [areas, setAreas] = useState([]);
    const [subareas, setSubareas] = useState([]);
    const [confirm, dialogo] = useConfirm();

    useEffect(() => {
        getAvaliacao(avaliacaoId, teste)
            .then((d) => { setDados(d); setRubrica(rubricaDe(d.avaliacao)); })
            .catch(() => setDados(false));
    }, [avaliacaoId, teste]);

    // Catálogo de áreas: só faz falta enquanto a avaliação está sendo preenchida.
    useEffect(() => {
        if (dados?.avaliacao?.status !== 'em_andamento') return;
        loadAreas().then(setAreas).catch(() => setAreas([]));
    }, [dados?.avaliacao?.status]);

    // Subáreas seguem a área que vale para o projeto (a sugerida, se houver).
    const areaEfetiva = rubrica.area_sugerida_id || dados?.projeto?.area_id || '';
    useEffect(() => {
        if (!areaEfetiva) { setSubareas([]); return; }
        loadSubareas(areaEfetiva).then(setSubareas).catch(() => setSubareas([]));
    }, [areaEfetiva]);

    function setCampo(campo, valor) {
        setAviso('');
        setRubrica((r) => {
            const proxima = { ...r, [campo]: valor };
            // Marcar como correta descarta a sugestão; trocar de área invalida a subárea escolhida.
            if (campo === 'area_correta' && valor === 'sim') proxima.area_sugerida_id = '';
            if (campo === 'subarea_correta' && valor === 'sim') proxima.subarea_sugerida_id = '';
            if (campo === 'area_sugerida_id') proxima.subarea_sugerida_id = '';
            return proxima;
        });
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
            setErros(e?.response?.data?.errors ?? {});
        } finally {
            setSalvando(false);
        }
    }

    async function concluir() {
        const ok = await confirm({
            title: 'Enviar avaliação', confirmLabel: 'Enviar',
            message: `Nota final: ${total} de ${notaMaxima}. Depois de enviada, a avaliação não pode ser alterada. Continuar?`,
        });
        if (!ok) return;

        setSalvando(true); setErro(''); setErros({}); setAviso('');
        try {
            const a = await concluirAvaliacao(avaliacaoId, payload(), teste);
            setDados((d) => ({ ...d, avaliacao: a }));
            onAtualizado?.();
        } catch (e) {
            setErro(e?.response?.data?.message || 'Não foi possível concluir.');
            setErros(e?.response?.data?.errors ?? {});
        } finally {
            setSalvando(false);
        }
    }

    /** Notas viram número, comentário em branco vira null e Sim/Não vira booleano. */
    function payload() {
        const numero = (v) => (v === '' || v === null ? null : Number(v));

        return {
            ...Object.fromEntries(
                QUESITOS.flatMap((q) => [
                    [`nota_${q.key}`, numero(rubrica[`nota_${q.key}`])],
                    [`comentario_${q.key}`, rubrica[`comentario_${q.key}`].trim() || null],
                ]),
            ),
            area_correta: paraBooleano(rubrica.area_correta),
            area_sugerida_id: rubrica.area_correta === 'nao' ? numero(rubrica.area_sugerida_id) : null,
            subarea_correta: paraBooleano(rubrica.subarea_correta),
            subarea_sugerida_id: rubrica.subarea_correta === 'nao' ? numero(rubrica.subarea_sugerida_id) : null,
        };
    }

    const av = dados && dados.avaliacao;
    const p = dados && dados.projeto;
    const notaMaxima = av?.nota_maxima ?? QUESITOS.length * NOTA_MAXIMA_QUESITO;
    const notasPreenchidas = QUESITOS.filter((q) => rubrica[`nota_${q.key}`] !== '');
    const total = notasPreenchidas.reduce((s, q) => s + Number(rubrica[`nota_${q.key}`]), 0);

    // Para enviar: as 3 notas, a conferência da área (+ sugestão se incorreta) e,
    // se marcou a subárea como incorreta, a subárea sugerida.
    const completa =
        notasPreenchidas.length === QUESITOS.length &&
        rubrica.area_correta !== '' &&
        (rubrica.area_correta === 'sim' || rubrica.area_sugerida_id !== '') &&
        (rubrica.subarea_correta !== 'nao' || rubrica.subarea_sugerida_id !== '');

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
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <Campo label="Categoria">{p.categoria}</Campo>
                                <Campo label="Área">{p.area}{p.subarea ? ` · ${p.subarea}` : ''}</Campo>
                                <Campo label="Instituição">{p.instituicao}</Campo>
                                <Campo label="Coorientador">{p.coorientador}</Campo>
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

                    {av?.status === 'em_andamento' && (
                        <section className="pt-2 space-y-3">
                            <h4 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">
                                Avaliação
                            </h4>
                            {QUESITOS.map((q) => (
                                <Quesito
                                    key={q.key}
                                    quesito={q}
                                    erros={erros}
                                    nota={rubrica[`nota_${q.key}`]}
                                    comentario={rubrica[`comentario_${q.key}`]}
                                    onNota={(v) => setCampo(`nota_${q.key}`, v)}
                                    onComentario={(v) => setCampo(`comentario_${q.key}`, v)}
                                />
                            ))}
                        </section>
                    )}

                    {av?.status === 'em_andamento' && (
                        <Classificacao
                            projeto={p}
                            valores={rubrica}
                            areas={areas}
                            subareas={subareas}
                            erros={erros}
                            onChange={setCampo}
                        />
                    )}

                    {av?.status === 'concluida' && (
                        <section className="pt-2 space-y-3">
                            <h4 className="font-display text-primary font-semibold border-b border-surface-variant pb-2">
                                Avaliação enviada
                            </h4>
                            <RubricaConcluida avaliacao={av} />
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
                                    Nota final{' '}
                                    <strong className="text-lg text-primary-container">{total}</strong>
                                    <span className="text-on-surface-variant/70"> de {notaMaxima}</span>
                                    {!completa && (
                                        <span className="block text-xs">
                                            Preencha os três quesitos e confira a área para enviar.
                                        </span>
                                    )}
                                </p>
                                <div className="flex items-center gap-3 flex-wrap">
                                    <Button type="button" variant="outline" loading={salvando} onClick={salvarRascunho}>
                                        <span className="material-symbols-outlined text-[18px]">save</span>
                                        Salvar rascunho
                                    </Button>
                                    <Button type="button" variant="success" loading={salvando} disabled={!completa} onClick={concluir}>
                                        Enviar avaliação
                                    </Button>
                                </div>
                            </div>
                        )}
                        {av.status === 'concluida' && (
                            <p className="text-sm text-on-surface text-right">
                                Avaliação concluída — nota{' '}
                                <strong className="text-secondary">{av.nota} de {notaMaxima}</strong>.
                            </p>
                        )}
                    </div>
                )}
            </div>
            {dialogo}
        </div>
    );
}
