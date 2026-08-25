import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getAvisoOpcoes, getAvisosVigentes, previaAviso, publicarAviso, encerrarAviso, getAvisos,
} from '../lib/admin.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

// Aviso publicado para um recorte da base. Vários podem estar no ar ao mesmo
// tempo — um por público; cada pessoa vê o mais recente que a alcança.
function AvisoSection({ opcoes, vigentes, onPublicado, onEncerrado }) {
    const [confirm, dialogo] = useConfirm();
    const [titulo, setTitulo] = useState(opcoes.modelo.titulo);
    const [mensagem, setMensagem] = useState(opcoes.modelo.mensagem);
    const [publicos, setPublicos] = useState(opcoes.publico_padrao ?? []);
    const [expiraEm, setExpiraEm] = useState('');
    const [alcance, setAlcance] = useState(opcoes.destinatarios);
    const [previa, setPrevia] = useState(null);
    const [publicando, setPublicando] = useState(false);
    const [msg, setMsg] = useState('');
    const [erro, setErro] = useState('');
    const mensagemRef = useRef(null);
    const [cursor, setCursor] = useState(null);

    /** Insere a variável onde o cursor está (ou no fim, se o campo nunca teve foco). */
    function inserirVariavel(chave) {
        const marcador = `{{${chave}}}`;
        const campo = mensagemRef.current;
        const inicio = campo?.selectionStart ?? mensagem.length;
        const fim = campo?.selectionEnd ?? mensagem.length;

        setMensagem(mensagem.slice(0, inicio) + marcador + mensagem.slice(fim));
        setCursor(inicio + marcador.length);
    }

    useEffect(() => {
        if (cursor === null) return;
        const campo = mensagemRef.current;
        if (campo) {
            campo.focus();
            campo.setSelectionRange(cursor, cursor);
        }
        setCursor(null);
    }, [cursor]);

    // Qualquer edição invalida a prévia: ela é do texto que foi conferido.
    useEffect(() => { setPrevia(null); }, [titulo, mensagem, publicos, expiraEm]);

    const preenchido = titulo.trim() !== '' && mensagem.trim() !== '' && publicos.length > 0;

    const alternarPublico = (valor) => setPublicos((atual) => (
        atual.includes(valor) ? atual.filter((p) => p !== valor) : [...atual, valor]
    ));

    async function verPrevia() {
        setErro(''); setMsg('');
        try {
            const resp = await previaAviso({ titulo, mensagem, publicos });
            setPrevia(resp);
            setAlcance(resp.destinatarios);
        } catch (e) {
            setErro(extractErrors(e).message);
        }
    }

    async function publicar() {
        const ok = await confirm({
            title: 'Publicar aviso',
            confirmLabel: 'Publicar',
            message: 'O aviso aparece em até 1 minuto para quem está no público escolhido. Se já houver '
                + 'um aviso no ar para exatamente esse público, ele sai e este entra no lugar. Continuar?',
        });
        if (!ok) return;

        setPublicando(true); setErro(''); setMsg('');
        try {
            const resp = await publicarAviso({ titulo, mensagem, publicos, expira_em: expiraEm || null });
            onPublicado(resp.data);
            setMsg(resp.meta?.message || 'Aviso publicado.');
            setPrevia(null);
        } catch (e) {
            setErro(extractErrors(e).message);
        } finally {
            setPublicando(false);
        }
    }

    async function encerrar(aviso) {
        const ok = await confirm({
            title: 'Encerrar aviso', confirmLabel: 'Encerrar', danger: true,
            message: 'O card some da tela de quem o recebia. O aviso continua no histórico, com quem viu e quem fechou. Continuar?',
        });
        if (!ok) return;

        setErro(''); setMsg('');
        try {
            const resp = await encerrarAviso(aviso.id);
            onEncerrado(aviso.id);
            setMsg(resp.meta?.message || 'Aviso encerrado.');
        } catch (e) {
            setErro(extractErrors(e).message);
        }
    }

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-3xl">
            <div className="flex items-center gap-2 flex-wrap mb-3">
                <h2 className="font-display text-primary font-semibold">Aviso na tela</h2>
                <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${
                    vigentes.length > 0 ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-variant text-on-surface-variant'
                }`}>
                    {vigentes.length === 0 ? 'Nenhum aviso no ar'
                        : vigentes.length === 1 ? 'Um aviso no ar' : `${vigentes.length} avisos no ar`}
                </span>
            </div>
            <p className="text-sm text-on-surface-variant mb-4">
                O card aparece em até 1 minuto para quem está no público escolhido, e cada pessoa pode
                fechá-lo. Avisos de públicos diferentes convivem no ar; cada pessoa vê no máximo um card
                — o mais recente que a alcança. O público escolhido alcança hoje <strong>{alcance}</strong> pessoa(s).
            </p>

            {msg && <div className="mb-4"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

            {vigentes.map((aviso) => (
                <div key={aviso.id} className="mb-5 rounded-xl border border-primary-container/40 bg-primary-fixed p-4">
                    <p className="text-xs font-semibold text-primary-container mb-1">No ar agora</p>
                    <h3 className="font-display font-semibold text-primary">{aviso.titulo}</h3>
                    <p className="text-sm text-on-surface mt-1 whitespace-pre-line">{aviso.mensagem}</p>
                    <div className="flex flex-wrap gap-1 mt-2">
                        {(aviso.publicos ?? []).map((p) => (
                            <span key={p.value} className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-surface-variant text-on-surface-variant">
                                {p.label}
                            </span>
                        ))}
                    </div>
                    <div className="flex items-center gap-3 flex-wrap mt-3">
                        <span className="text-xs text-on-surface-variant">Publicado em {aviso.publicado_em}</span>
                        {aviso.expira_em_label && (
                            <span className="text-xs text-on-surface-variant">Expira em {aviso.expira_em_label}</span>
                        )}
                        <Button type="button" variant="outline" onClick={() => encerrar(aviso)}>
                            <span className="material-symbols-outlined text-[18px]">stop_circle</span>
                            Encerrar aviso
                        </Button>
                    </div>
                </div>
            ))}

            <div className="space-y-4">
                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="aviso-titulo">Título</label>
                    <input
                        id="aviso-titulo"
                        type="text"
                        maxLength={120}
                        className={campoClass}
                        value={titulo}
                        onChange={(e) => setTitulo(e.target.value)}
                    />
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="aviso-mensagem">Mensagem</label>
                    <textarea
                        id="aviso-mensagem"
                        ref={mensagemRef}
                        rows={6}
                        maxLength={2000}
                        className={campoClass}
                        value={mensagem}
                        onChange={(e) => setMensagem(e.target.value)}
                    />
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        <span className="text-xs text-on-surface-variant">Inserir variável:</span>
                        {opcoes.variaveis.map((variavel) => (
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
                    <p className="text-xs text-on-surface-variant pt-1">
                        As variáveis são trocadas na hora em que a pessoa lê — "{'{{tempo_restante}}'}" mostra
                        o tempo que falta de verdade para cada um.
                    </p>
                </div>

                <fieldset className="space-y-1">
                    <legend className="text-sm font-semibold text-on-surface">Quem recebe</legend>
                    <p className="text-xs text-on-surface-variant">
                        Combine quantos públicos quiser — os mesmos da mala direta. Contas inativas e de
                        teste nunca recebem.
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-1 pt-1">
                        {(opcoes.publicos ?? []).map((p) => (
                            <label key={p.value} className="flex items-start gap-2 px-2 py-1.5 rounded-lg cursor-pointer hover:bg-surface-variant/40">
                                <input
                                    type="checkbox"
                                    checked={publicos.includes(p.value)}
                                    onChange={() => alternarPublico(p.value)}
                                    className="mt-0.5 accent-[color:var(--color-primary-container,#43157A)]"
                                />
                                <span className="min-w-0">
                                    <span className="block text-sm text-on-surface">{p.label}</span>
                                    <span className="block text-xs text-on-surface-variant">{p.descricao}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    {publicos.length === 0 && (
                        <p className="text-xs text-error pt-1">Escolha ao menos um público.</p>
                    )}
                </fieldset>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="aviso-expira">
                        Expira em (opcional)
                    </label>
                    <input
                        id="aviso-expira"
                        type="datetime-local"
                        className={campoClass}
                        value={expiraEm}
                        onChange={(e) => setExpiraEm(e.target.value)}
                    />
                    <p className="text-xs text-on-surface-variant">
                        Passada a data (hora de Campo Grande), o card sai da tela sozinho — sem precisar
                        encerrar à mão. Em branco, fica no ar até você encerrar.
                    </p>
                </div>

                {previa && (
                    <div className="rounded-xl border border-outline-variant bg-surface p-4">
                        <p className="text-xs font-semibold text-on-surface-variant mb-1">Prévia (como aparece agora)</p>
                        <h3 className="font-display font-semibold text-primary">{previa.titulo}</h3>
                        <p className="text-sm text-on-surface mt-1 whitespace-pre-line">{previa.mensagem}</p>
                    </div>
                )}

                <div className="flex gap-2 flex-wrap">
                    <Button type="button" variant="outline" disabled={!preenchido} onClick={verPrevia}>
                        <span className="material-symbols-outlined text-[18px]">visibility</span>
                        Ver prévia
                    </Button>
                    <Button type="button" loading={publicando} disabled={!preenchido} onClick={publicar}>
                        <span className="material-symbols-outlined text-[18px]">campaign</span>
                        Publicar aviso
                    </Button>
                </div>
            </div>
            {dialogo}
        </div>
    );
}

// Avisos já publicados, com quantos viram e quantos fecharam cada um.
function HistoricoAvisos({ recarregar }) {
    const [pagina, setPagina] = useState(1);
    const [lista, setLista] = useState(null);

    const carregar = useCallback(() => {
        getAvisos(pagina).then(setLista).catch(() => setLista({ data: [], meta: null }));
    }, [pagina]);

    useEffect(() => { carregar(); }, [carregar, recarregar]);

    const avisos = lista?.data ?? [];
    const meta = lista?.meta;

    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mt-6 max-w-3xl">
            <h2 className="font-display text-primary font-semibold mb-1">Avisos publicados</h2>
            <p className="text-sm text-on-surface-variant mb-4">
                Quantas pessoas viram e quantas fecharam cada aviso. Abra um deles para ver nome por
                nome — inclusive quem ainda não viu.
            </p>

            {lista === null ? (
                <div className="text-center py-6 text-on-surface-variant">
                    <span className="inline-block w-6 h-6 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : avisos.length === 0 ? (
                <p className="text-sm text-on-surface-variant">Nenhum aviso publicado ainda.</p>
            ) : (
                <ul className="divide-y divide-outline-variant/30">
                    {avisos.map((a) => (
                        <li key={a.id} className="py-3">
                            <Link to={`/admin/comunicacao/avisos/${a.id}`} className="group flex items-center gap-3 flex-wrap">
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="text-sm font-semibold text-on-surface group-hover:text-primary truncate">{a.titulo}</span>
                                        {a.ativo && (
                                            <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">
                                                No ar
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-xs text-on-surface-variant">
                                        Publicado em {a.publicado_em} por {a.autor_nome}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3 shrink-0 text-center">
                                    <div className="w-14">
                                        <div className="text-base font-bold text-primary-container">{a.vistos}</div>
                                        <div className="text-[10px] text-on-surface-variant">Viram</div>
                                    </div>
                                    <div className="w-14">
                                        <div className="text-base font-bold text-secondary">{a.fechados}</div>
                                        <div className="text-[10px] text-on-surface-variant">Fecharam</div>
                                    </div>
                                    <div className="w-14">
                                        <div className="text-base font-bold text-error">{a.nao_vistos}</div>
                                        <div className="text-[10px] text-on-surface-variant">Não viram</div>
                                    </div>
                                </div>
                                <span className="material-symbols-outlined text-on-surface-variant group-hover:translate-x-0.5 transition-transform">chevron_right</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {meta && meta.ultima_pagina > 1 && (
                <div className="flex items-center justify-between gap-3 mt-4">
                    <Button type="button" variant="outline" disabled={meta.pagina <= 1} onClick={() => setPagina(meta.pagina - 1)}>
                        Anterior
                    </Button>
                    <span className="text-sm text-on-surface-variant">Página {meta.pagina} de {meta.ultima_pagina}</span>
                    <Button type="button" variant="outline" disabled={meta.pagina >= meta.ultima_pagina} onClick={() => setPagina(meta.pagina + 1)}>
                        Próxima
                    </Button>
                </div>
            )}
        </div>
    );
}

export default function AdminAvisos() {
    const [opcoes, setOpcoes] = useState(null);
    const [vigentes, setVigentes] = useState([]);
    // Publicar ou encerrar muda os números do histórico: este contador o recarrega.
    const [versao, setVersao] = useState(0);

    useEffect(() => {
        getAvisoOpcoes().then(setOpcoes).catch(() => setOpcoes(null));
        getAvisosVigentes().then(setVigentes).catch(() => setVigentes([]));
    }, []);

    const carregando = opcoes === null;

    return (
        <AppShell>
            <Link to="/admin/comunicacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Comunicação
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avisos</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                O card que aparece na tela de quem está conectado, para o público que você escolher,
                e o relatório de quem viu, fechou ou ainda não viu cada aviso. As datas de inscrição
                ficam em Parametrização → Inscrições.
            </p>

            {carregando ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    <AvisoSection
                        opcoes={opcoes}
                        vigentes={vigentes}
                        onPublicado={(aviso) => {
                            // O publicado entra na frente; o do mesmo público sai do ar.
                            setVigentes((atual) => [aviso, ...atual.filter((a) => a.ativo !== false && a.id !== aviso.id)]);
                            getAvisosVigentes().then(setVigentes).catch(() => {});
                            setVersao((v) => v + 1);
                        }}
                        onEncerrado={(id) => {
                            setVigentes((atual) => atual.filter((a) => a.id !== id));
                            setVersao((v) => v + 1);
                        }}
                    />
                    <HistoricoAvisos recarregar={versao} />
                </>
            )}
        </AppShell>
    );
}
