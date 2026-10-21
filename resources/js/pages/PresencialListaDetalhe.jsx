import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import JustificativaDialog from '../components/JustificativaDialog.jsx';
import { Alert, Button, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    exportarListaPresencial, gerarListaPresencialDefinitiva, getListaPresencial, reativarListaPresencial,
    rotularListaPresencial,
} from '../lib/listasPresenciais.js';

const pill = 'ml-2 align-middle text-xs font-semibold px-2 py-0.5 rounded-full';

const FORMATOS = [
    { formato: 'txt', rotulo: 'TXT' },
    { formato: 'csv', rotulo: 'CSV' },
    { formato: 'xlsx', rotulo: 'Excel' },
    { formato: 'pdf', rotulo: 'PDF' },
];

const nota = (valor) => (valor === null || valor === undefined ? 'sem nota' : Number(valor).toFixed(3).replace('.', ','));

/** Os itens agrupados por categoria+área, na ordem em que o servidor os mandou. */
export function agrupar(itens) {
    const grupos = [];
    const porChave = {};

    for (const item of itens) {
        const chave = `${item.categoria ?? ''}|${item.area ?? ''}`;
        if (!porChave[chave]) {
            porChave[chave] = { chave, titulo: [item.categoria, item.area ?? 'Sem área'].filter(Boolean).join(' · '), itens: [] };
            grupos.push(porChave[chave]);
        }
        porChave[chave].itens.push(item);
    }

    return grupos;
}

/** A equipe numa linha: estudantes, orientador e coorientador. */
const equipe = (i) => [
    ...(i.alunos ?? []),
    i.orientador && `${i.orientador} (orientador)`,
    i.coorientador && `${i.coorientador} (coorientador)`,
].filter(Boolean).join(', ');

/** Apelido e descrição: editáveis a qualquer momento, sem justificativa. */
function RotuloLista({ lista, onSalvo }) {
    const [editando, setEditando] = useState(false);
    const [nome, setNome] = useState('');
    const [descricao, setDescricao] = useState('');
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');

    async function salvar(e) {
        e.preventDefault();
        setSalvando(true); setErro('');
        try {
            onSalvo(await rotularListaPresencial(lista.id, { nome: nome.trim(), descricao: descricao.trim() }));
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
                    onClick={() => { setNome(lista.nome ?? ''); setDescricao(lista.descricao ?? ''); setErro(''); setEditando(true); }}
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

/** Um premiado: colocação, código, título, escola, equipe e a média. */
function ItemPremiado({ item, acoes }) {
    return (
        <li className="px-4 py-3 flex items-start gap-3">
            <span className="w-12 shrink-0 text-center font-display text-lg font-bold text-primary-container" aria-label={item.colocacao ? `${item.colocacao}º lugar` : 'Sem colocação'}>
                {item.colocacao ? `${item.colocacao}º` : '—'}
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm text-on-surface">
                    {item.codigo && <span className="font-semibold text-primary-container mr-1">{item.codigo}</span>}
                    {item.titulo}
                    {item.manual && <span className={`${pill} bg-primary-fixed text-primary-container`}>incluído à mão</span>}
                </p>
                <p className="text-xs text-on-surface-variant">{item.escola}</p>
                <p className="text-xs text-on-surface-variant">{equipe(item)}</p>
            </div>
            <div className="text-right shrink-0">
                <p className="text-sm font-semibold text-secondary">{nota(item.media)}</p>
                {item.media !== null && <p className="text-[10px] text-on-surface-variant">{item.avaliacoes} avaliação(ões){item.parcial ? ' · parcial' : ''}</p>}
            </div>
            {acoes}
        </li>
    );
}

/**
 * Ranking presencial → uma **lista da premiação** (Sprint 251): os premiados
 * por nota, por grupo categoria+área com a colocação, e os arquivos.
 *
 * No rascunho a lista se edita à vontade; gerar fecha. A final gerada vira o
 * resultado oficial da premiação — a lista final online, que define os
 * finalistas, não muda.
 */
export default function PresencialListaDetalhe() {
    const { id } = useParams();
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [gerando, setGerando] = useState(false);
    const [baixando, setBaixando] = useState('');
    const [dialogo, setDialogo] = useState(null); // { tipo }
    const [salvando, setSalvando] = useState(false);
    const [erroDialogo, setErroDialogo] = useState('');
    const [confirm, dialogoConfirmacao] = useConfirm();

    const carregar = useCallback(() => {
        getListaPresencial(id)
            .then((d) => { setDados(d); setErro(''); })
            .catch(() => setErro('Não foi possível carregar a lista.'));
    }, [id]);

    useEffect(() => { carregar(); }, [carregar]);

    async function gerar() {
        const final = dados.lista.tipo === 'final';
        const ok = await confirm({
            title: final ? 'Gerar a lista final?' : 'Gerar a lista preliminar?',
            message: final
                ? 'Ela vira a lista final presencial ativa: o resultado oficial da premiação, que o Cerimonial lê. A final ativa de agora fica inativa (dá para reativá-la depois). Depois de gerada, cada alteração pede justificativa.'
                : 'Ela fica registrada como preliminar. Depois de gerada, cada alteração pede justificativa.',
            confirmLabel: final ? 'Gerar e ativar' : 'Gerar',
        });
        if (!ok) return;

        setGerando(true); setErro(''); setSucesso('');
        try {
            const resp = await gerarListaPresencialDefinitiva(id);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? 'Lista gerada.');
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível gerar a lista.');
        } finally {
            setGerando(false);
        }
    }

    async function baixar(formato) {
        setBaixando(formato); setErro('');
        try {
            await exportarListaPresencial(id, formato);
        } catch {
            setErro('Não foi possível gerar o arquivo.');
        } finally {
            setBaixando('');
        }
    }

    async function confirmar(justificativa) {
        setSalvando(true); setErroDialogo('');
        try {
            const resp = await reativarListaPresencial(id, justificativa);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? 'Lista reativada.');
            setDialogo(null);
        } catch (e) {
            setErroDialogo(extractErrors(e).message || 'Não foi possível concluir.');
        } finally {
            setSalvando(false);
        }
    }

    const lista = dados?.lista;
    const itens = dados?.itens ?? [];

    return (
        <AppShell>
            <Link to="/admin/presencial/listas" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Listas da premiação
            </Link>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            {dados === null ? (
                !erro && (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                )
            ) : (
                <>
                    <div className="flex items-start justify-between gap-3 flex-wrap mb-6 max-w-3xl">
                        <div className="min-w-0">
                            <h1 className="font-display text-2xl font-semibold text-primary mb-1">
                                {lista.nome}
                                <span className={`${pill} bg-primary-fixed text-primary-container`}>{lista.tipo === 'preliminar' ? 'preliminar' : 'final'}</span>
                                {lista.vigente && <span className={`${pill} bg-secondary-container text-on-secondary-container`}>ativa</span>}
                                {lista.tipo === 'final' && !lista.rascunho && !lista.vigente && (
                                    <span className={`${pill} bg-surface-variant text-on-surface-variant`}>inativa</span>
                                )}
                                {lista.rascunho && <span className={`${pill} bg-surface-variant text-on-surface-variant`}>rascunho</span>}
                                {lista.demo && <span className={`${pill} bg-surface-variant text-on-surface-variant`}>ensaio</span>}
                            </h1>
                            <RotuloLista lista={lista} onSalvo={(resp) => { setDados(resp.data); setSucesso(resp.meta?.message ?? ''); }} />
                            <p className="text-sm text-on-surface-variant">
                                Versão {lista.versao} · {lista.projetos} {lista.projetos === 1 ? 'projeto por nota' : 'projetos por nota'}.
                                {lista.rascunho
                                    ? ' Rascunho: inclua e retire à vontade, sem justificativa.'
                                    : ' Cada alteração exige justificativa e gera uma versão nova.'}
                            </p>
                            {lista.origens?.length > 0 && (
                                <p className="text-xs text-on-surface-variant mt-1">Montada das listas: {lista.origens.map((o) => o.nome).join(', ')}.</p>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2 shrink-0">
                            {lista.rascunho && (
                                <Button type="button" loading={gerando} disabled={lista.projetos === 0} onClick={gerar}>
                                    <span className="material-symbols-outlined text-[20px]">campaign</span>
                                    {lista.tipo === 'preliminar' ? 'Gerar lista preliminar' : 'Gerar lista final'}
                                </Button>
                            )}
                            {lista.tipo === 'final' && !lista.rascunho && !lista.vigente && (
                                <Button type="button" variant="outline" onClick={() => { setErroDialogo(''); setDialogo({ tipo: 'reativar' }); }}>
                                    <span className="material-symbols-outlined text-[20px]">restart_alt</span>
                                    Tornar ativa
                                </Button>
                            )}
                        </div>
                    </div>

                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                        <p className="text-sm font-semibold text-on-surface mb-1">Exportar</p>
                        <p className="text-xs text-on-surface-variant mb-2">
                            O TXT sai no formato da lista online (código, título, escola, estudantes e orientação), com a
                            colocação de cada projeto. CSV e Excel trazem uma linha por premiação.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {FORMATOS.map((f) => (
                                <Button key={f.formato} type="button" variant="outline" loading={baixando === f.formato} disabled={baixando !== ''} onClick={() => baixar(f.formato)} aria-label={`Baixar ${f.rotulo}`}>
                                    <span className="material-symbols-outlined text-[18px]">download</span>
                                    {f.rotulo}
                                </Button>
                            ))}
                        </div>
                    </div>

                    <h2 className="font-display text-lg font-semibold text-on-surface mb-2">Premiados por nota</h2>
                    {itens.length === 0 ? (
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm max-w-3xl">
                            Nenhum projeto por nota nesta lista.
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {agrupar(itens).map((grupo) => (
                                <section key={grupo.chave} className="bg-surface-container-lowest rounded-xl fetec-card-shadow max-w-3xl overflow-hidden" aria-label={grupo.titulo}>
                                    <div className="px-4 py-3 border-b border-outline-variant/40 bg-surface-container-low">
                                        <h3 className="font-semibold text-on-surface text-sm">{grupo.titulo}</h3>
                                        <p className="text-xs text-on-surface-variant">
                                            {grupo.itens.length} {grupo.itens.length === 1 ? 'projeto' : 'projetos'} · colocação pela média presencial
                                        </p>
                                    </div>
                                    <ul className="divide-y divide-outline-variant/30">
                                        {grupo.itens.map((i) => <ItemPremiado key={i.projeto_id} item={i} />)}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    )}
                </>
            )}

            {dialogo?.tipo === 'reativar' && (
                <JustificativaDialog
                    titulo="Tornar esta a lista final ativa"
                    projeto={lista.nome}
                    acao="Tornar ativa"
                    ajuda="Ela passa a ser o resultado oficial da premiação, no lugar da final ativa de agora. Fica em Registros → Lista final."
                    salvando={salvando}
                    erro={erroDialogo}
                    onConfirmar={confirmar}
                    onFechar={() => setDialogo(null)}
                />
            )}
            {dialogoConfirmacao}
        </AppShell>
    );
}
