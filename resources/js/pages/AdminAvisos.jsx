import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getAvisoOpcoes, getAvisoAtivoAdmin, previaAviso, publicarAviso, encerrarAviso, getAvisos,
} from '../lib/admin.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

// Aviso publicado para os orientadores conectados. Um por vez: publicar um novo
// encerra o anterior.
function AvisoSection({ opcoes, ativo, onAtivo }) {
    const [confirm, dialogo] = useConfirm();
    const [titulo, setTitulo] = useState(opcoes.modelo.titulo);
    const [mensagem, setMensagem] = useState(opcoes.modelo.mensagem);
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
    useEffect(() => { setPrevia(null); }, [titulo, mensagem]);

    const preenchido = titulo.trim() !== '' && mensagem.trim() !== '';

    async function verPrevia() {
        setErro(''); setMsg('');
        try {
            setPrevia(await previaAviso({ titulo, mensagem }));
        } catch (e) {
            setErro(extractErrors(e).message);
        }
    }

    async function publicar() {
        const ok = await confirm({
            title: 'Publicar aviso',
            confirmLabel: 'Publicar',
            message: ativo
                ? 'O aviso que está no ar sai e este entra no lugar, aparecendo para os orientadores conectados em até 1 minuto. Continuar?'
                : 'O aviso aparece para os orientadores conectados em até 1 minuto. Continuar?',
        });
        if (!ok) return;

        setPublicando(true); setErro(''); setMsg('');
        try {
            const resp = await publicarAviso({ titulo, mensagem });
            onAtivo(resp.data);
            setMsg(resp.meta?.message || 'Aviso publicado.');
            setPrevia(null);
        } catch (e) {
            setErro(extractErrors(e).message);
        } finally {
            setPublicando(false);
        }
    }

    async function encerrar() {
        const ok = await confirm({
            title: 'Encerrar aviso', confirmLabel: 'Encerrar', danger: true,
            message: 'O card some da tela dos orientadores. O aviso continua no histórico, com quem viu e quem fechou. Continuar?',
        });
        if (!ok) return;

        setErro(''); setMsg('');
        try {
            const resp = await encerrarAviso(ativo.id);
            onAtivo(null);
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
                    ativo ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-variant text-on-surface-variant'
                }`}>
                    {ativo ? 'Um aviso no ar' : 'Nenhum aviso no ar'}
                </span>
            </div>
            <p className="text-sm text-on-surface-variant mb-4">
                O card aparece para os <strong>{opcoes.destinatarios}</strong> orientador(es) ativo(s)
                que estiverem no sistema, em até 1 minuto, e cada um pode fechá-lo. Só um aviso fica no
                ar por vez — publicar um novo encerra o anterior.
            </p>

            {msg && <div className="mb-4"><Alert type="info">{msg}</Alert></div>}
            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

            {ativo && (
                <div className="mb-5 rounded-xl border border-primary-container/40 bg-primary-fixed p-4">
                    <p className="text-xs font-semibold text-primary-container mb-1">No ar agora</p>
                    <h3 className="font-display font-semibold text-primary">{ativo.titulo}</h3>
                    <p className="text-sm text-on-surface mt-1 whitespace-pre-line">{ativo.mensagem}</p>
                    <div className="flex items-center gap-3 flex-wrap mt-3">
                        <span className="text-xs text-on-surface-variant">Publicado em {ativo.publicado_em}</span>
                        <Button type="button" variant="outline" onClick={encerrar}>
                            <span className="material-symbols-outlined text-[18px]">stop_circle</span>
                            Encerrar aviso
                        </Button>
                    </div>
                </div>
            )}

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
                Quantos orientadores viram e quantos fecharam cada aviso. Abra um deles para ver
                nome por nome — inclusive quem ainda não viu.
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
    const [ativo, setAtivo] = useState(null);
    // Publicar ou encerrar muda os números do histórico: este contador o recarrega.
    const [versao, setVersao] = useState(0);

    useEffect(() => {
        getAvisoOpcoes().then(setOpcoes).catch(() => setOpcoes(null));
        getAvisoAtivoAdmin().then(setAtivo).catch(() => setAtivo(null));
    }, []);

    const carregando = opcoes === null;

    return (
        <AppShell>
            <Link to="/admin/comunicacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Comunicação
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avisos</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                O card que aparece na tela dos orientadores conectados, e o relatório de quem viu,
                fechou ou ainda não viu cada aviso. As datas de inscrição ficam em
                Parametrização → Inscrições.
            </p>

            {carregando ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    <AvisoSection
                        opcoes={opcoes}
                        ativo={ativo}
                        onAtivo={(a) => { setAtivo(a); setVersao((v) => v + 1); }}
                    />
                    <HistoricoAvisos recarregar={versao} />
                </>
            )}
        </AppShell>
    );
}
