import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, useConfirm } from '../components/ui.jsx';
import BuscaCombobox from '../components/BuscaCombobox.jsx';
import { extractErrors } from '../lib/auth.jsx';
import IdentificacaoParticipantes from '../components/IdentificacaoParticipantes.jsx';
import EnvioCodigosFinalistas from '../components/EnvioCodigosFinalistas.jsx';
import ExportarListaFinal from '../components/ExportarListaFinal.jsx';
import ExportarProjetosPesquisa from '../components/ExportarProjetosPesquisa.jsx';
import {
    getListaFinal, adicionarNaListaFinal, removerDaListaFinal, baixarListaOficial,
    publicarListaFinal, reativarListaFinal, reordenarListaFinal, definirCodigoListaFinal,
    rotularListaFinal,
} from '../lib/admin.js';

const MIN_JUSTIFICATIVA = 5;

/**
 * Diálogo de justificativa das alterações na lista oficial. Incluir ou retirar
 * um projeto é uma decisão fora do recorte por nota, então cada uma precisa
 * ficar explicada em Registros → Lista final.
 */
function JustificativaDialog({ titulo, projeto, acao, salvando, erro, onConfirmar, onFechar, ajuda }) {
    const [texto, setTexto] = useState('');
    const pode = texto.trim().length >= MIN_JUSTIFICATIVA;

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">{titulo}</h3>
                    <p className="text-sm text-on-surface-variant truncate">{projeto}</p>
                </div>

                {erro && <Alert>{erro}</Alert>}

                <label className="block">
                    <span className="text-sm font-semibold text-on-surface">
                        Justificativa <span className="text-error">*</span>
                    </span>
                    <textarea
                        value={texto}
                        onChange={(e) => setTexto(e.target.value)}
                        rows={4}
                        maxLength={500}
                        className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                    />
                    <span className="text-xs text-on-surface-variant">
                        {ajuda ?? 'Fica em Registros → Lista final, junto do seu nome. Uma versão nova do arquivo é gerada.'}
                    </span>
                </label>

                <div className="flex justify-end gap-3">
                    <Button type="button" variant="outline" onClick={onFechar} disabled={salvando}>Cancelar</Button>
                    <Button type="button" loading={salvando} disabled={!pode} onClick={() => onConfirmar(texto.trim())}>
                        {acao}
                    </Button>
                </div>
            </div>
        </div>
    );
}

/** Os itens agrupados por categoria+área, na ordem em que o servidor os mandou. */
function agrupar(itens) {
    const grupos = [];
    const porChave = {};

    for (const item of itens) {
        const chave = `${item.categoria ?? ''}|${item.area ?? ''}`;
        if (!porChave[chave]) {
            porChave[chave] = { chave, categoria: item.categoria, area: item.area, itens: [] };
            grupos.push(porChave[chave]);
        }
        porChave[chave].itens.push(item);
    }

    return grupos;
}

const iconeClass = 'p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-variant disabled:opacity-30 transition-colors';

/**
 * Os avisos de código (Sprint 177). Só o duplicado é vermelho — é o único que
 * impede gerar a lista; os outros pedem atenção, não correção.
 */
const AVISOS = {
    faltante: { rotulo: 'sem código', classe: 'bg-yellow-100 text-yellow-900 border-yellow-300' },
    fora_padrao: { rotulo: 'fora do padrão', classe: 'bg-orange-100 text-orange-900 border-orange-300' },
    fora_sequencia: { rotulo: 'fora da sequência', classe: 'bg-orange-100 text-orange-900 border-orange-300' },
    duplicado: { rotulo: 'código repetido', classe: 'bg-error-container text-on-error-container border-error/40' },
};

function SeloAviso({ aviso }) {
    const a = AVISOS[aviso];
    if (!a) return null;

    return (
        <span className={`ml-1 text-[11px] font-semibold px-1.5 py-0.5 rounded-full border align-middle ${a.classe}`}>
            {a.rotulo}
        </span>
    );
}

/** As frases do resumo, na ordem em que o admin precisa ler. */
function frasesDosAvisos(r) {
    if (!r) return [];
    return [
        r.duplicados > 0 && {
            grave: true,
            texto: `${r.duplicados} projeto(s) com código repetido: ${r.codigos_duplicados.map((d) => `${d.codigo} (${d.projetos.join(', ')})`).join('; ')}. A lista só pode ser gerada depois de corrigir.`,
        },
        r.faltantes > 0 && { texto: `${r.faltantes} projeto(s) sem código.` },
        r.fora_padrao > 0 && { texto: `${r.fora_padrao} código(s) fora do padrão (CATEGORIA.ÁREA-000 da categoria e da área do projeto).` },
        r.fora_sequencia > 0 && { texto: `${r.fora_sequencia} código(s) fora da sequência do grupo (salto ou buraco na numeração).` },
    ].filter(Boolean);
}

/**
 * O resumo dos avisos de código no topo da composição: o admin vê tudo antes de
 * gerar, sem descer a lista inteira procurando selo.
 */
function ResumoAvisos({ resumo }) {
    const frases = frasesDosAvisos(resumo);
    if (frases.length === 0) return null;
    const grave = frases.some((f) => f.grave);

    return (
        <div
            role="status"
            className={`mb-4 max-w-3xl rounded-xl border p-4 ${grave ? 'border-error/50 bg-error-container/40' : 'border-orange-300 bg-orange-50'}`}
        >
            <p className="font-semibold text-sm text-on-surface flex items-center gap-1">
                <span className={`material-symbols-outlined text-[20px] ${grave ? 'text-error' : 'text-orange-700'}`}>
                    {grave ? 'error' : 'warning'}
                </span>
                Atenção aos códigos desta lista
            </p>
            <ul className="mt-1 space-y-0.5 text-sm text-on-surface list-disc pl-5">
                {frases.map((f) => (
                    <li key={f.texto} className={f.grave ? 'text-error font-semibold' : ''}>{f.texto}</li>
                ))}
            </ul>
        </div>
    );
}

/** A ordem dos ids com o item de `de` levado para a posição `para`. */
function mover(ids, de, para) {
    if (para < 0 || para >= ids.length || de === para) return ids;
    const nova = [...ids];
    const [item] = nova.splice(de, 1);
    nova.splice(para, 0, item);
    return nova;
}

/**
 * Um grupo categoria+área da lista. Os códigos são numerados dentro do grupo
 * (FET.AGR-001, -002…), então é aqui que a ordem se mexe.
 *
 * Desde a Sprint 178 não há "modo de reordenar": o admin **arrasta** o projeto
 * pela alça (ou usa as setas, que servem no teclado e no celular) e, ao soltar,
 * o grupo inteiro é renumerado na ordem nova — na hora, no rascunho; com
 * justificativa, numa lista já gerada. O arraste fica dentro do grupo: o
 * prefixo do código é a categoria e a área do projeto, e mudar de grupo seria
 * mudar a classificação dele. O código também pode ser digitado à mão.
 */
function GrupoDaLista({ grupo, ordemProvisoria, onReordenar, edicaoCodigo, onEditarCodigo, onSalvarCodigo, onRetirar, salvando, destaque, gerada }) {
    const [arrastando, setArrastando] = useState(null);
    const [alvo, setAlvo] = useState(null);
    const provisoria = ordemProvisoria !== null;
    const itens = provisoria
        ? ordemProvisoria.map((id) => grupo.itens.find((i) => i.projeto_id === id)).filter(Boolean)
        : grupo.itens;
    const ids = itens.map((i) => i.projeto_id);
    const titulo = [grupo.categoria, grupo.area ?? 'Sem área'].filter(Boolean).join(' · ');
    const movel = itens.length > 1 && !salvando && !provisoria && edicaoCodigo === null;

    function soltar(para) {
        if (arrastando !== null) {
            const nova = mover(ids, arrastando, para);
            if (nova !== ids) onReordenar(nova);
        }
        setArrastando(null);
        setAlvo(null);
    }

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow max-w-3xl overflow-hidden" aria-label={titulo}>
            <div className="px-4 py-3 border-b border-outline-variant/40 bg-surface-container-low">
                <h3 className="font-semibold text-on-surface text-sm">{titulo}</h3>
                <p className="text-xs text-on-surface-variant">
                    {grupo.itens.length} {grupo.itens.length === 1 ? 'projeto' : 'projetos'}
                    {grupo.itens.length > 1 && (gerada
                        ? ' · arraste para mudar a ordem: o grupo é renumerado depois da justificativa.'
                        : ' · arraste para mudar a ordem: o grupo é renumerado na hora.')}
                </p>
            </div>

            <ul className="divide-y divide-outline-variant/40">
                {itens.map((i, indice) => {
                    const editando = edicaoCodigo?.projetoId === i.projeto_id;

                    return (
                        <li
                            key={i.projeto_id}
                            id={`projeto-${i.projeto_id}`}
                            draggable={movel}
                            onDragStart={(e) => {
                                setArrastando(indice);
                                // O Firefox só arrasta com algum dado no evento.
                                e.dataTransfer?.setData?.('text/plain', String(i.projeto_id));
                            }}
                            onDragOver={(e) => {
                                // Só aceita o que veio deste grupo.
                                if (arrastando === null) return;
                                e.preventDefault();
                                if (alvo !== indice) setAlvo(indice);
                            }}
                            onDrop={(e) => { e.preventDefault(); soltar(indice); }}
                            onDragEnd={() => { setArrastando(null); setAlvo(null); }}
                            className={`p-4 flex flex-col sm:flex-row sm:items-center gap-3 transition-colors ${
                                arrastando === indice ? 'opacity-50' : ''
                            } ${alvo === indice && arrastando !== indice ? (arrastando < indice ? 'border-b-2 border-b-primary-container' : 'border-t-2 border-t-primary-container') : ''} ${
                                destaque === i.projeto_id ? 'bg-primary-fixed/50' : ''
                            }`}
                        >
                            {itens.length > 1 && (
                                <span
                                    className={`material-symbols-outlined text-on-surface-variant hidden sm:inline ${movel ? 'cursor-grab' : 'opacity-30'}`}
                                    title={movel ? 'Arraste para mudar a ordem' : undefined}
                                    aria-hidden="true"
                                >
                                    drag_indicator
                                </span>
                            )}
                            <div className="min-w-0 flex-1">
                                {editando ? (
                                    <form
                                        className="flex flex-wrap items-center gap-2 mb-1"
                                        onSubmit={(e) => { e.preventDefault(); onSalvarCodigo(i); }}
                                    >
                                        <input
                                            aria-label={`Código de ${i.titulo}`}
                                            value={edicaoCodigo.valor}
                                            onChange={(e) => onEditarCodigo({ projetoId: i.projeto_id, valor: e.target.value })}
                                            maxLength={30}
                                            autoFocus
                                            className="font-mono text-sm w-40 bg-surface border border-outline-variant rounded-lg px-2 py-1 text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                                        />
                                        <Button type="submit" loading={salvando}>Salvar</Button>
                                        <Button type="button" variant="outline" onClick={() => onEditarCodigo(null)} disabled={salvando}>Cancelar</Button>
                                        <span className="basis-full text-xs text-on-surface-variant">
                                            Padrão: {i.prefixo ?? 'CAT.ARE'}-001. Em branco, o projeto fica sem código.
                                        </span>
                                    </form>
                                ) : null}
                                <p className="text-sm font-semibold text-on-surface">
                                    {!editando && (
                                        <span className="inline-flex items-center gap-1 mr-2 align-middle">
                                            <span className="font-mono text-xs text-on-surface-variant">{provisoria || !i.codigo ? '—' : i.codigo}</span>
                                            {!provisoria && (
                                                <button
                                                    type="button"
                                                    onClick={() => onEditarCodigo({ projetoId: i.projeto_id, valor: i.codigo })}
                                                    title={`Editar o código de ${i.titulo}`}
                                                    aria-label={`Editar o código de ${i.titulo}`}
                                                    className="text-on-surface-variant hover:text-primary"
                                                >
                                                    <span className="material-symbols-outlined text-[16px]">edit</span>
                                                </button>
                                            )}
                                        </span>
                                    )}
                                    {i.titulo}
                                    {!provisoria && (i.avisos ?? []).map((a) => <SeloAviso key={a} aviso={a} />)}
                                    {i.manual && (
                                        <span className="ml-2 text-xs font-semibold px-2 py-0.5 rounded-full bg-primary-fixed text-primary-container">
                                            incluído à mão
                                        </span>
                                    )}
                                </p>
                                <p className="text-xs text-on-surface-variant truncate">
                                    {[i.categoria, i.area, i.escola].filter(Boolean).join(' · ')}
                                </p>
                            </div>
                            <div className="flex gap-1 shrink-0 items-center">
                                {itens.length > 1 && (
                                    <>
                                        <button type="button" onClick={() => onReordenar(mover(ids, indice, indice - 1))} disabled={!movel || indice === 0}
                                            title={`Mover ${i.titulo} para cima`} aria-label={`Mover ${i.titulo} para cima`} className={iconeClass}>
                                            <span className="material-symbols-outlined text-[20px]">arrow_upward</span>
                                        </button>
                                        <button type="button" onClick={() => onReordenar(mover(ids, indice, indice + 1))} disabled={!movel || indice === itens.length - 1}
                                            title={`Mover ${i.titulo} para baixo`} aria-label={`Mover ${i.titulo} para baixo`} className={iconeClass}>
                                            <span className="material-symbols-outlined text-[20px]">arrow_downward</span>
                                        </button>
                                    </>
                                )}
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-error border-error/40 hover:bg-error-container/40 ml-1"
                                    onClick={() => onRetirar(i)}
                                    disabled={provisoria}
                                >
                                    <span className="material-symbols-outlined text-[20px]">playlist_remove</span>
                                    Retirar
                                </Button>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/**
 * Apelido e descrição da lista (Sprint 180): o que distingue uma preliminar da
 * outra na relação. É rótulo, não composição — salva na hora, sem
 * justificativa e sem versão nova, também numa lista já gerada.
 */
function RotuloLista({ lista, onSalvo }) {
    const [editando, setEditando] = useState(false);
    const [nome, setNome] = useState('');
    const [descricao, setDescricao] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');

    function abrir() {
        setNome(lista.nome ?? '');
        setDescricao(lista.descricao ?? '');
        setErro('');
        setEditando(true);
    }

    async function salvar(e) {
        e.preventDefault();
        setSalvando(true); setErro('');
        try {
            onSalvo(await rotularListaFinal(lista.id, { nome: nome.trim(), descricao: descricao.trim() }));
            setEditando(false);
        } catch (err) {
            const { message, fields } = extractErrors(err);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    if (!editando) {
        return (
            <div className="mb-1">
                {lista.descricao && <p className="text-sm text-on-surface whitespace-pre-line">{lista.descricao}</p>}
                <button
                    type="button"
                    onClick={abrir}
                    className="text-xs text-on-surface-variant hover:text-primary inline-flex items-center gap-1"
                >
                    <span className="material-symbols-outlined text-[16px]">edit</span>
                    {lista.descricao ? 'Editar apelido e descrição' : 'Editar apelido e acrescentar descrição'}
                </button>
            </div>
        );
    }

    return (
        <form onSubmit={salvar} className="my-2 space-y-2 rounded-lg border border-outline-variant p-3 bg-surface-container-lowest">
            {erro && <Alert>{erro}</Alert>}
            <input
                value={nome}
                onChange={(e) => setNome(e.target.value)}
                aria-label="Apelido da lista"
                placeholder="Apelido (em branco, volta ao nome padrão)"
                maxLength={120}
                className="w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface"
            />
            <textarea
                value={descricao}
                onChange={(e) => setDescricao(e.target.value)}
                aria-label="Descrição da lista"
                placeholder="O que esta lista reúne"
                maxLength={1000}
                rows={2}
                className="w-full rounded-lg border border-outline-variant bg-surface px-3 py-2 text-sm text-on-surface"
            />
            <div className="flex gap-2">
                <Button type="submit" loading={salvando}>Salvar</Button>
                <Button type="button" variant="outline" disabled={salvando} onClick={() => setEditando(false)}>Cancelar</Button>
            </div>
        </form>
    );
}

/**
 * A composição de uma lista final: quem está dentro e quem pode entrar.
 *
 * É a mesma tela nos dois momentos da vida da lista. Como **prévia** (rascunho
 * recém-gerado), ela é onde o admin confere o que as cotas produziram, corrige
 * à mão e só então baixa o TXT ou publica. Como lista **oficial**, é onde a
 * composição continua sendo mantida depois de publicada.
 *
 * Cada inclusão ou remoção exige justificativa, **sobe a versão** da lista e
 * gera um arquivo novo — a lista vigente é o que define os finalistas da feira,
 * então a auditoria da composição é parte do recurso.
 */
export default function AvaliacaoListaFinalDetalhe() {
    const { id } = useParams();
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');
    const [candidato, setCandidato] = useState(null);
    const [dialogo, setDialogo] = useState(null); // { tipo, projeto }
    const [salvando, setSalvando] = useState(false);
    const [erroDialogo, setErroDialogo] = useState('');
    const [publicando, setPublicando] = useState(false);
    const [sucesso, setSucesso] = useState('');
    const [confirm, dialogoConfirmacao] = useConfirm();
    // Arraste (Sprint 178): a ordem nova de um grupo enquanto ela é salva — ou
    // enquanto a justificativa não vem, numa lista já gerada.
    const [ordemProvisoria, setOrdemProvisoria] = useState(null); // { chave, ids }
    const [edicaoCodigo, setEdicaoCodigo] = useState(null); // { projetoId, valor }
    const [salvandoCodigos, setSalvandoCodigos] = useState(false);
    // O projeto que acabou de mudar de código: a lista se reordena pelo código
    // (Sprint 177), e o destaque mostra para onde ele foi.
    const [destaque, setDestaque] = useState(null);

    useEffect(() => {
        if (destaque === null) return undefined;
        document.getElementById(`projeto-${destaque}`)?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
        const t = setTimeout(() => setDestaque(null), 4000);
        return () => clearTimeout(t);
    }, [destaque]);

    const carregar = useCallback(() => {
        getListaFinal(id)
            .then((d) => { setDados(d); setErro(''); })
            .catch(() => setErro('Não foi possível carregar a lista.'));
    }, [id]);

    useEffect(() => { carregar(); }, [carregar]);

    async function confirmar(justificativa) {
        setSalvando(true);
        setErroDialogo('');
        try {
            if (dialogo.tipo === 'reativar') {
                const resp = await reativarListaFinal(id, justificativa);
                setDados(resp.data);
                setSucesso(resp.meta?.message ?? 'Lista reativada.');
            } else if (dialogo.tipo === 'ordem' || dialogo.tipo === 'codigo') {
                aplicarCodigos(await enviarCodigos(dialogo, justificativa), dialogo.tipo === 'codigo' ? dialogo.projeto.id : null);
            } else {
                const novo = dialogo.tipo === 'incluir'
                    ? await adicionarNaListaFinal(id, dialogo.projeto.id, justificativa)
                    : await removerDaListaFinal(id, dialogo.projeto.id, justificativa);
                setDados(novo);
                setCandidato(null);
            }
            setDialogo(null);
        } catch (e) {
            setErroDialogo(extractErrors(e).message || 'Não foi possível concluir.');
        } finally {
            setSalvando(false);
        }
    }

    /**
     * No rascunho (Sprint 164) a edição é livre: inclui e retira na hora, sem
     * justificativa. Depois de gerada, abre o diálogo.
     */
    async function alterar(tipo, projeto) {
        if (!dados.lista.rascunho) {
            setErroDialogo('');
            setDialogo({ tipo, projeto });
            return;
        }

        setErro('');
        try {
            const novo = tipo === 'incluir'
                ? await adicionarNaListaFinal(id, projeto.id)
                : await removerDaListaFinal(id, projeto.id);
            setDados(novo);
            setCandidato(null);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível alterar a lista.');
        }
    }

    /** A chamada da ordem ou do código, com a justificativa quando a lista já foi gerada. */
    function enviarCodigos(pedido, justificativa = null) {
        return pedido.tipo === 'ordem'
            ? reordenarListaFinal(id, pedido.ids, justificativa)
            : definirCodigoListaFinal(id, pedido.projeto.id, pedido.codigo, justificativa);
    }

    function aplicarCodigos(resp, projetoId = null) {
        setDados(resp.data);
        setSucesso(resp.meta?.message ?? '');
        setOrdemProvisoria(null);
        setEdicaoCodigo(null);
        setDestaque(projetoId);
    }

    /**
     * Ordem e código seguem a regra da composição: no rascunho valem na hora;
     * depois de gerada, pedem justificativa (e sobem a versão).
     */
    async function salvarCodigos(pedido) {
        setErro(''); setSucesso('');
        if (!dados.lista.rascunho) {
            setErroDialogo('');
            setDialogo(pedido);
            return;
        }

        setSalvandoCodigos(true);
        try {
            aplicarCodigos(await enviarCodigos(pedido), pedido.tipo === 'codigo' ? pedido.projeto.id : null);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível salvar.');
            setOrdemProvisoria(null);
        } finally {
            setSalvandoCodigos(false);
        }
    }

    /**
     * Soltar o projeto (ou apertar uma seta) salva a ordem do grupo — o servidor
     * renumera todos os códigos dele na sequência nova.
     */
    function reordenar(grupo, ids) {
        setEdicaoCodigo(null);
        setOrdemProvisoria({ chave: grupo.chave, ids });
        salvarCodigos({
            tipo: 'ordem', chave: grupo.chave, ids,
            projeto: { titulo: [grupo.categoria, grupo.area].filter(Boolean).join(' · ') },
        });
    }

    async function publicar() {
        // Avisos de código (Sprint 177): repetido barra; o resto se confirma.
        const avisos = frasesDosAvisos(dados.lista.avisos_codigos).filter((f) => !f.grave).map((f) => `• ${f.texto}`);
        const final = dados.lista.tipo === 'final';

        // A final gerada passa a valer para a etapa presencial inteira.
        if (final || avisos.length > 0) {
            const ok = await confirm({
                title: final ? 'Gerar a lista final?' : 'Gerar a lista preliminar?',
                message: [
                    avisos.length > 0 && `A lista vai ser gerada com estes avisos:\n${avisos.join('\n')}`,
                    final && 'Ela vira a lista final ativa: os projetos dela passam a ser os finalistas do credenciamento, do mapa, dos crachás e da avaliação presencial. A final ativa de agora fica inativa (dá para reativá-la depois).',
                ].filter(Boolean).join('\n\n'),
                confirmLabel: final ? 'Gerar e ativar' : 'Gerar mesmo assim',
            });
            if (!ok) return;
        }

        setPublicando(true);
        setErro('');
        try {
            const resp = await publicarListaFinal(id);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? 'Lista gerada.');
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível gerar a lista.');
        } finally {
            setPublicando(false);
        }
    }

    const lista = dados?.lista;
    const itens = dados?.itens ?? [];
    // O combobox espera { id, nome, detalhe } e faz a busca por conta própria.
    const candidatos = (dados?.candidatos ?? []).map((c) => ({
        id: c.id,
        nome: c.titulo,
        detalhe: [c.categoria, c.area, c.media !== null ? `média ${c.media}` : 'sem avaliação']
            .filter(Boolean).join(' · '),
    }));

    return (
        <AppShell>
            <Link to="/admin/avaliacao/listas-finais" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Listas finais
            </Link>

            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}
            {sucesso && <div className="mb-4"><Alert type="info">{sucesso}</Alert></div>}

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <>
                    <div className="flex items-start justify-between gap-3 flex-wrap mb-6 max-w-3xl">
                        <div className="min-w-0">
                            <h1 className="font-display text-2xl font-semibold text-primary mb-1">
                                {lista.nome}
                                <span className="ml-2 align-middle text-xs font-semibold px-2 py-0.5 rounded-full bg-primary-fixed text-primary-container">
                                    {lista.tipo === 'preliminar' ? 'preliminar' : 'final'}
                                </span>
                                {lista.vigente && (
                                    <span className="ml-2 align-middle text-xs font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">
                                        ativa
                                    </span>
                                )}
                                {lista.tipo === 'final' && !lista.rascunho && !lista.vigente && (
                                    <span className="ml-2 align-middle text-xs font-semibold px-2 py-0.5 rounded-full bg-surface-variant text-on-surface-variant">
                                        inativa
                                    </span>
                                )}
                                {lista.rascunho && (
                                    <span className="ml-2 align-middle text-xs font-semibold px-2 py-0.5 rounded-full bg-surface-variant text-on-surface-variant">
                                        rascunho
                                    </span>
                                )}
                            </h1>
                            <RotuloLista
                                lista={lista}
                                onSalvo={(resp) => { setDados(resp.data); setSucesso(resp.meta?.message ?? ''); }}
                            />
                            <p className="text-sm text-on-surface-variant">
                                Versão {lista.versao} · {lista.projetos} {lista.projetos === 1 ? 'projeto' : 'projetos'}.
                                {lista.rascunho
                                    ? ' Rascunho: inclua, retire e reordene à vontade, sem justificativa.'
                                    : ' Cada alteração (inclusive de ordem ou código) exige justificativa e gera um arquivo novo.'}
                            </p>
                            {lista.origens?.length > 0 && (
                                <p className="text-xs text-on-surface-variant mt-1">
                                    Montada das listas: {lista.origens.map((o) => o.nome).join(', ')}.
                                </p>
                            )}
                            {lista.rascunho && (
                                <p className="text-sm text-on-surface-variant mt-1">
                                    {lista.tipo === 'preliminar'
                                        ? 'Ao gerar, ela fica registrada como preliminar — várias convivem, e nenhuma define finalista.'
                                        : 'Ao gerar, ela vira a lista final ativa: os projetos dela passam a ser os finalistas da etapa presencial, no lugar da final ativa de agora.'}
                                </p>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2 shrink-0">
                            <Button type="button" variant="outline" onClick={() => baixarListaOficial(id)}>
                                <span className="material-symbols-outlined text-[20px]">download</span>
                                Baixar TXT
                            </Button>
                            {lista.rascunho && (
                                <Button
                                    type="button"
                                    loading={publicando}
                                    disabled={lista.projetos === 0 || (lista.avisos_codigos?.duplicados ?? 0) > 0}
                                    title={(lista.avisos_codigos?.duplicados ?? 0) > 0 ? 'Corrija os códigos repetidos antes de gerar.' : undefined}
                                    onClick={publicar}
                                >
                                    <span className="material-symbols-outlined text-[20px]">campaign</span>
                                    {lista.tipo === 'preliminar' ? 'Gerar lista preliminar' : 'Gerar lista final'}
                                </Button>
                            )}
                            {lista.tipo === 'final' && !lista.rascunho && !lista.vigente && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => { setErroDialogo(''); setDialogo({ tipo: 'reativar', projeto: { titulo: lista.nome } }); }}
                                >
                                    <span className="material-symbols-outlined text-[20px]">restart_alt</span>
                                    Tornar ativa
                                </Button>
                            )}
                        </div>
                    </div>

                    {/* Incluir projeto */}
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                        <p className="text-sm font-semibold text-on-surface mb-2">Incluir projeto</p>
                        <div className="flex flex-col sm:flex-row gap-2 sm:items-center">
                            <div className="flex-1">
                                <BuscaCombobox
                                    options={candidatos}
                                    value={candidato}
                                    onChange={setCandidato}
                                    placeholder="Buscar entre os projetos submetidos fora da lista…"
                                    vazio="Nenhum projeto submetido fora da lista"
                                />
                            </div>
                            <Button
                                type="button"
                                disabled={!candidato}
                                onClick={() => alterar('incluir', { id: candidato.id, titulo: candidato.nome })}
                            >
                                <span className="material-symbols-outlined text-[20px]">playlist_add</span>
                                Incluir
                            </Button>
                        </div>
                    </div>

                    <ResumoAvisos resumo={lista.avisos_codigos} />

                    {/* Composição, por grupo categoria+área — é dentro do grupo que o código numera. */}
                    {lista.codigos_enviados_em && (
                        <div className="mb-4 max-w-3xl">
                            <Alert type="info">
                                Os códigos já foram enviados aos finalistas em{' '}
                                {new Date(lista.codigos_enviados_em).toLocaleDateString('pt-BR')}. Mudar a ordem ou um código
                                deixa a equipe com um número diferente do e-mail — reenvie os códigos depois.
                            </Alert>
                        </div>
                    )}
                    {itens.length === 0 ? (
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm max-w-3xl">
                            A lista está vazia.
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {agrupar(itens).map((grupo) => (
                                <GrupoDaLista
                                    key={grupo.chave}
                                    grupo={grupo}
                                    ordemProvisoria={ordemProvisoria?.chave === grupo.chave ? ordemProvisoria.ids : null}
                                    onReordenar={(ids) => reordenar(grupo, ids)}
                                    gerada={!lista.rascunho}
                                    edicaoCodigo={edicaoCodigo}
                                    onEditarCodigo={setEdicaoCodigo}
                                    onSalvarCodigo={(i) => salvarCodigos({
                                        tipo: 'codigo', codigo: edicaoCodigo.valor.trim(), projeto: { id: i.projeto_id, titulo: i.titulo },
                                    })}
                                    onRetirar={(i) => alterar('remover', { id: i.projeto_id, titulo: i.titulo })}
                                    salvando={salvandoCodigos}
                                    destaque={destaque}
                                />
                            ))}
                        </div>
                    )}
                </>
            )}

            {lista && <ExportarListaFinal listaId={lista.id} />}
            {lista && <ExportarProjetosPesquisa listaId={lista.id} itens={itens} />}
            {lista?.vigente && !lista.rascunho && <EnvioCodigosFinalistas listaId={lista.id} />}
            {lista?.tipo === 'final' && <IdentificacaoParticipantes listaId={lista.id} />}

            {dialogo && (
                <JustificativaDialog
                    titulo={{
                        incluir: 'Incluir na lista', remover: 'Retirar da lista', reativar: 'Tornar esta a lista final ativa',
                        ordem: 'Salvar a nova ordem', codigo: dialogo.codigo ? `Trocar o código para ${dialogo.codigo}` : 'Deixar o projeto sem código',
                    }[dialogo.tipo]}
                    projeto={dialogo.projeto.titulo}
                    acao={{ incluir: 'Incluir', remover: 'Retirar', reativar: 'Tornar ativa', ordem: 'Salvar ordem', codigo: 'Trocar código' }[dialogo.tipo]}
                    ajuda={{
                        reativar: 'Os projetos desta lista passam a ser os finalistas da etapa presencial, no lugar da final ativa de agora. Fica em Registros → Lista final.',
                        ordem: 'Os códigos do grupo são renumerados na ordem nova (inclusive os digitados à mão). Cada código que mudar fica em Registros → Lista final, e uma versão nova do arquivo é gerada.',
                        codigo: 'O código antigo e o novo ficam em Registros → Lista final, e uma versão nova do arquivo é gerada.',
                    }[dialogo.tipo]}
                    salvando={salvando}
                    erro={erroDialogo}
                    onConfirmar={confirmar}
                    onFechar={() => { setDialogo(null); setOrdemProvisoria(null); }}
                />
            )}
            {dialogoConfirmacao}
        </AppShell>
    );
}
