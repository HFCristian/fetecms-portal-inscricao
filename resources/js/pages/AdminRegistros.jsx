import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, Field, DateInput } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getRegistros, exportarRegistrosCsv } from '../lib/admin.js';

/** Cor da tag de cada tipo de evento. */
const TAG = {
    submissao: 'bg-secondary-container text-on-secondary-container',
    cancelamento: 'bg-primary-fixed text-primary-container',
    exclusao: 'bg-error-container text-on-error-container',
    troca_email: 'bg-surface-variant text-on-surface-variant',
    avaliacao_liberacao: 'bg-secondary-container text-on-secondary-container',
    avaliacao_encerramento: 'bg-error-container text-on-error-container',
    avaliacao_min_avaliador: 'bg-primary-fixed text-primary-container',
    avaliacao_min_projeto: 'bg-surface-variant text-on-surface-variant',
    projeto_categoria: 'bg-primary-fixed text-primary-container',
    projeto_area: 'bg-secondary-container text-on-secondary-container',
    projeto_subarea: 'bg-surface-variant text-on-surface-variant',
    projeto_video: 'bg-primary-fixed text-primary-container',
    rascunho_alteracao: 'bg-surface-variant text-on-surface-variant',
    rascunho_submissao: 'bg-secondary-container text-on-secondary-container',
    lista_final_oficializada: 'bg-secondary-container text-on-secondary-container',
    lista_final_projeto_adicionado: 'bg-primary-fixed text-primary-container',
    lista_final_projeto_removido: 'bg-error-container text-on-error-container',
    credenciamento_realizado: 'bg-secondary-container text-on-secondary-container',
    credenciamento_kit_retirado: 'bg-primary-fixed text-primary-container',
};

const ICONE = {
    submissao: 'send',
    cancelamento: 'undo',
    exclusao: 'delete',
    troca_email: 'alternate_email',
    avaliacao_liberacao: 'play_circle',
    avaliacao_encerramento: 'stop_circle',
    avaliacao_min_avaliador: 'person_check',
    avaliacao_min_projeto: 'fact_check',
    projeto_categoria: 'category',
    projeto_area: 'account_tree',
    projeto_subarea: 'lan',
    projeto_video: 'movie',
    rascunho_alteracao: 'edit',
    rascunho_submissao: 'send',
    lista_final_oficializada: 'fact_check',
    lista_final_projeto_adicionado: 'playlist_add',
    lista_final_projeto_removido: 'playlist_remove',
    credenciamento_realizado: 'how_to_reg',
    credenciamento_kit_retirado: 'local_mall',
};

/** Título, texto e placeholder de busca de cada seção da trilha. */
const SECOES = {
    inscricoes: {
        titulo: 'Registros · Inscrições',
        descricao: 'Tudo o que aconteceu com as inscrições: submissões, cancelamentos, exclusões e '
            + 'trocas de e-mail — com quem fez, quando e de qual projeto.',
        placeholder: 'Buscar por e-mail, nome ou título do projeto…',
        vazio: 'Nenhum registro por enquanto.',
    },
    avaliacao: {
        titulo: 'Registros · Avaliação Online',
        descricao: 'As mudanças de parâmetro do período de avaliação — início, fim e os mínimos de '
            + 'avaliações por avaliador e por projeto —, com o valor anterior e o novo.',
        placeholder: 'Buscar pelo e-mail ou nome de quem alterou…',
        vazio: 'Nenhuma mudança de parâmetro registrada por enquanto.',
    },
    projetos: {
        titulo: 'Registros · Projetos',
        descricao: 'As correções que o admin fez em projetos já submetidos — categoria, área, '
            + 'subárea e link do vídeo —, sempre com a justificativa de quem alterou.',
        placeholder: 'Buscar por título do projeto, orientador ou admin…',
        vazio: 'Nenhuma correção registrada por enquanto.',
    },
    rascunhos: {
        titulo: 'Registros · Rascunhos',
        descricao: 'O admin terminando a inscrição de outra pessoa depois do prazo: cada campo que '
            + 'ele alterou, com o valor anterior e o novo, e a justificativa da submissão.',
        placeholder: 'Buscar por título do projeto, orientador ou admin…',
        vazio: 'Nenhum rascunho mexido pelo admin por enquanto.',
    },
    lista_final: {
        titulo: 'Registros · Lista final',
        descricao: 'A lista oficial da feira: quando foi publicada e cada projeto acrescentado ou '
            + 'retirado depois, sempre com a justificativa de quem alterou.',
        placeholder: 'Buscar por título do projeto ou admin…',
        vazio: 'Nenhuma lista oficial publicada por enquanto.',
    },
    credenciamento: {
        titulo: 'Registros · Credenciamento',
        descricao: 'O balcão do evento: quem credenciou cada projeto, quando, quem faltou, o que '
            + 'ficou ausente na conferência dos documentos e cada retirada de kit.',
        placeholder: 'Buscar por título do projeto ou quem credenciou…',
        vazio: 'Nenhum credenciamento registrado por enquanto.',
    },
};

const PAPEL = { orientador: 'Orientador', avaliador: 'Avaliador', admin: 'Administrador' };

const SEM_FILTRO = { tipos: [], de: '', ate: '', busca: '' };

function Tag({ tipo, label }) {
    return (
        <span className={`inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-full shrink-0 ${TAG[tipo] ?? ''}`}>
            <span className="material-symbols-outlined text-[14px]">{ICONE[tipo] ?? 'label'}</span>
            {label}
        </span>
    );
}

function Registro({ item }) {
    const data = item.ocorrido_em ? new Date(item.ocorrido_em) : null;

    return (
        <div className="flex flex-col sm:flex-row sm:items-center gap-2 py-3">
            <Tag tipo={item.tipo} label={item.tipo_label} />
            <div className="flex-1 min-w-0">
                <p className="text-sm text-on-surface truncate">
                    <strong>{item.autor_email}</strong>
                    {item.autor_nome ? <span className="text-on-surface-variant"> · {item.autor_nome}</span> : null}
                    {item.autor_role ? <span className="text-on-surface-variant"> · {PAPEL[item.autor_role] ?? item.autor_role}</span> : null}
                </p>
                <p className="text-xs text-on-surface-variant truncate">
                    {item.projeto_titulo
                        ? <>Projeto: {item.projeto_titulo}{item.projeto_categoria ? ` (${item.projeto_categoria})` : ''}</>
                        : item.detalhes_texto || '—'}
                    {item.projeto_titulo && item.detalhes_texto ? ` · ${item.detalhes_texto}` : ''}
                </p>
                {item.por_terceiro && item.dono_email && (
                    <p className="text-xs text-error">Inscrição de {item.dono_email}</p>
                )}
            </div>
            <span className="text-xs text-on-surface-variant shrink-0 sm:text-right">
                {data ? data.toLocaleDateString('pt-BR') : '—'}
                <span className="hidden sm:block">{data ? data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : ''}</span>
                <span className="sm:hidden"> {data ? data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : ''}</span>
            </span>
        </div>
    );
}

/**
 * Uma seção da trilha de registros ("inscricoes", "avaliacao", "projetos",
 * "rascunhos" ou "lista_final"), com filtro por
 * tipo, período e busca, e export CSV do mesmo recorte que está na tela.
 */
export default function AdminRegistros({ secao = 'inscricoes' }) {
    const textos = SECOES[secao] ?? SECOES.inscricoes;
    const [filtros, setFiltros] = useState(SEM_FILTRO);
    const [busca, setBusca] = useState('');
    const [page, setPage] = useState(1);
    const [lista, setLista] = useState(null);
    const [meta, setMeta] = useState(null);
    const [alert, setAlert] = useState('');
    const [exportando, setExportando] = useState(false);

    // A busca é aplicada com debounce; os demais filtros, na hora.
    useEffect(() => {
        const t = setTimeout(() => {
            setFiltros((f) => (f.busca === busca.trim() ? f : { ...f, busca: busca.trim() }));
            setPage(1);
        }, 300);
        return () => clearTimeout(t);
    }, [busca]);

    const carregar = useCallback(() => {
        setLista(null);
        setAlert('');
        getRegistros({ ...filtros, secao, page })
            .then((resp) => { setLista(resp.data); setMeta(resp.meta); })
            .catch((e) => { setLista([]); setAlert(extractErrors(e).message); });
    }, [filtros, page, secao]);

    useEffect(() => carregar(), [carregar]);

    function alternarTipo(tipo) {
        setFiltros((f) => ({
            ...f,
            tipos: f.tipos.includes(tipo) ? f.tipos.filter((t) => t !== tipo) : [...f.tipos, tipo],
        }));
        setPage(1);
    }

    function mudarPeriodo(campo, valor) {
        setFiltros((f) => ({ ...f, [campo]: valor }));
        setPage(1);
    }

    function limpar() {
        setBusca('');
        setFiltros(SEM_FILTRO);
        setPage(1);
    }

    async function exportar() {
        setAlert('');
        setExportando(true);
        try {
            await exportarRegistrosCsv({ ...filtros, secao });
        } catch {
            setAlert('Não foi possível gerar o CSV. Tente novamente.');
        } finally {
            setExportando(false);
        }
    }

    const tipos = meta?.tipos ?? [];
    const totais = meta?.totais_por_tipo ?? {};
    const temFiltro = filtros.tipos.length > 0 || filtros.de || filtros.ate || filtros.busca;

    return (
        <AppShell>
            <div className="flex flex-wrap items-end justify-between gap-3 mb-6">
                <div>
                    <Link to="/admin/registros" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-2">
                        <span className="material-symbols-outlined text-[18px]">arrow_back</span> Registros
                    </Link>
                    <h1 className="font-display text-2xl font-semibold text-primary mb-1">{textos.titulo}</h1>
                    <p className="text-on-surface-variant max-w-3xl">{textos.descricao}</p>
                </div>
                <Button type="button" variant="outline" loading={exportando} onClick={exportar}>
                    <span className="material-symbols-outlined text-[20px]">download</span>
                    Exportar CSV
                </Button>
            </div>

            {alert && <div className="mb-4 max-w-4xl"><Alert>{alert}</Alert></div>}

            <div className="max-w-4xl">
                {/* Tags de tipo: clique para filtrar (contagem respeita período e busca) */}
                <div className="flex flex-wrap gap-2 mb-3">
                    {tipos.map((t) => {
                        const ativo = filtros.tipos.includes(t.value);
                        return (
                            <button
                                key={t.value}
                                type="button"
                                aria-pressed={ativo}
                                aria-label={`Filtrar por ${t.label}`}
                                onClick={() => alternarTipo(t.value)}
                                className={`inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-sm font-semibold border transition-colors ${
                                    ativo
                                        ? 'bg-primary-container text-on-primary border-primary-container'
                                        : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                                }`}
                            >
                                <span className="material-symbols-outlined text-[16px]">{ICONE[t.value]}</span>
                                {t.label}
                                <span className={ativo ? 'opacity-80' : 'text-outline'}>{`(${totais[t.value] ?? 0})`}</span>
                            </button>
                        );
                    })}
                </div>

                <div className="flex flex-col md:flex-row gap-2 mb-3">
                    <div className="relative flex-1">
                        <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">search</span>
                        <input
                            type="text"
                            aria-label="Buscar nos registros"
                            className="w-full bg-surface-container-lowest border border-outline-variant rounded-lg pl-10 pr-3 py-2.5 text-on-surface placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 transition-all outline-none"
                            placeholder={textos.placeholder}
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                        />
                    </div>
                    <div className="flex gap-2">
                        <div className="w-40">
                            <Field label="De">
                                <DateInput value={filtros.de} onChange={(e) => mudarPeriodo('de', e.target.value)} aria-label="Data inicial" />
                            </Field>
                        </div>
                        <div className="w-40">
                            <Field label="Até">
                                <DateInput value={filtros.ate} onChange={(e) => mudarPeriodo('ate', e.target.value)} aria-label="Data final" />
                            </Field>
                        </div>
                    </div>
                </div>

                <div className="flex items-center justify-between gap-3 mb-2">
                    <p className="text-xs text-on-surface-variant">
                        {meta ? `${meta.total} ${meta.total === 1 ? 'registro' : 'registros'}${temFiltro ? ' no filtro atual' : ''}.` : ''}
                    </p>
                    {temFiltro && (
                        <button type="button" onClick={limpar} className="text-xs font-semibold text-primary hover:underline">
                            Limpar filtros
                        </button>
                    )}
                </div>

                {lista === null ? (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                ) : lista.length === 0 ? (
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">
                        {temFiltro ? 'Nenhum registro neste filtro.' : textos.vazio}
                    </div>
                ) : (
                    <>
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 divide-y divide-outline-variant/30">
                            {lista.map((item) => <Registro key={item.id} item={item} />)}
                        </div>
                        {meta && meta.ultima_pagina > 1 && (
                            <div className="flex items-center justify-between gap-3 mt-4">
                                <span className="text-xs text-on-surface-variant">Página {meta.pagina_atual} de {meta.ultima_pagina}</span>
                                <div className="flex gap-2">
                                    <Button type="button" variant="outline" disabled={meta.pagina_atual <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Anterior</Button>
                                    <Button type="button" variant="outline" disabled={meta.pagina_atual >= meta.ultima_pagina} onClick={() => setPage((p) => p + 1)}>Próxima</Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppShell>
    );
}
