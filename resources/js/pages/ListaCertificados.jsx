import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Alert, Button, Input, Select } from '../components/ui.jsx';
import {
    getListaCertificados, getLinhasCertificados, baixarListaCertificados,
} from '../lib/certificados.js';

const FORMATOS = [
    ['xlsx', 'Excel (SICERT)', 'table_view'],
    ['csv', 'CSV (SICERT)', 'csv'],
    ['pdf', 'PDF', 'picture_as_pdf'],
    ['txt', 'TXT', 'description'],
];

const cpfFormatado = (cpf) => cpf?.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—');

/**
 * A lista de certificados gerada, em tela cheia (Sprint 259): os números de
 * cada categoria, a tabela com tudo o que vai para o certificado e as
 * exportações — Excel e CSV no modelo de importação do SICERT, PDF e TXT.
 *
 * É o retrato do momento em que a lista foi gerada. Editar volta ao assistente;
 * o que mudar lá só chega aqui quando a lista é gerada de novo.
 */
export default function ListaCertificados() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [lista, setLista] = useState(null);
    const [linhas, setLinhas] = useState(null);
    const [meta, setMeta] = useState(null);
    const [categoria, setCategoria] = useState('');
    const [busca, setBusca] = useState('');
    const [pagina, setPagina] = useState(1);
    const [baixando, setBaixando] = useState(null);
    const [erro, setErro] = useState('');

    useEffect(() => {
        getListaCertificados(id).then(setLista).catch(() => setErro('Lista de certificados não encontrada.'));
    }, [id]);

    useEffect(() => {
        if (lista?.status !== 'gerada') return undefined;
        const t = setTimeout(() => {
            getLinhasCertificados(id, { categoria, q: busca.trim(), page: pagina })
                .then((r) => { setLinhas(r.data); setMeta(r.meta); })
                .catch(() => setErro('Não foi possível carregar os certificados.'));
        }, 250);
        return () => clearTimeout(t);
    }, [id, lista?.status, categoria, busca, pagina]);

    async function baixar(formato) {
        setBaixando(formato); setErro('');
        try {
            await baixarListaCertificados(id, formato, categoria);
        } catch {
            setErro('Não foi possível gerar o arquivo.');
        } finally {
            setBaixando(null);
        }
    }

    const resumo = meta?.resumo ?? [];
    const rotuloDe = (chave) => resumo.find((c) => c.chave === chave)?.rotulo ?? chave;
    const editar = () => navigate(`/admin/certificados/listas/${id}/assistente`);

    return (
        <div className="min-h-screen bg-surface">
            <header className="sticky top-0 z-30 bg-surface-container-lowest border-b border-outline-variant/40 px-4 md:px-8 py-3 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3 min-w-0">
                    <Link to="/admin/certificados" aria-label="Voltar para Certificados" className="rounded-full p-1.5 hover:bg-surface-variant text-primary">
                        <span className="material-symbols-outlined">arrow_back</span>
                    </Link>
                    <div className="min-w-0">
                        <h1 className="font-display text-xl font-semibold text-primary truncate">{lista?.nome ?? 'Lista de certificados'}</h1>
                        {lista && (
                            <p className="text-xs text-on-surface-variant">
                                {lista.status === 'gerada'
                                    ? `Gerada em ${dataHora(lista.gerada_em)}${lista.gerada_por ? ` por ${lista.gerada_por}` : ''}`
                                    : 'Rascunho — ainda não gerada'}
                                {lista.publicos.length > 0 && ` · ${lista.publicos.join(', ')}`}
                            </p>
                        )}
                    </div>
                </div>
                <Button type="button" variant="outline" disabled={!lista} onClick={editar}>
                    <span className="material-symbols-outlined text-[18px]">edit</span>
                    Editar
                </Button>
            </header>

            <main className="px-4 md:px-8 py-6 space-y-6">
                {erro && <Alert>{erro}</Alert>}
                {lista?.desatualizada && (
                    <Alert type="warning">
                        A lista foi alterada depois de gerada. Os arquivos abaixo ainda são da última geração — abra
                        Editar e gere de novo para atualizá-los.
                    </Alert>
                )}

                {lista && lista.status !== 'gerada' ? (
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center">
                        <p className="text-on-surface mb-3">Esta lista ainda é rascunho: termine o assistente para gerá-la.</p>
                        <Button type="button" onClick={editar}>Continuar o assistente</Button>
                    </div>
                ) : (
                    <>
                        <section aria-label="Resumo por categoria" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {resumo.map((c) => (
                                <button
                                    key={c.chave}
                                    type="button"
                                    onClick={() => { setCategoria(categoria === c.chave ? '' : c.chave); setPagina(1); }}
                                    aria-pressed={categoria === c.chave}
                                    className={`text-left rounded-xl p-4 fetec-card-shadow border-2 transition-colors ${categoria === c.chave ? 'border-primary-container bg-primary-fixed/40' : 'border-transparent bg-surface-container-lowest hover:border-outline-variant'}`}
                                >
                                    <p className="text-xs font-semibold uppercase tracking-wide text-on-surface-variant">{c.rotulo}</p>
                                    <p className="font-display text-3xl font-bold text-primary mt-1">{c.certificados}</p>
                                    <p className="text-xs text-on-surface-variant">
                                        certificado(s) · {c.pessoas} pessoa(s) · {c.horas}h no total
                                    </p>
                                    {c.carga_texto && <p className="text-xs text-on-surface mt-1">{c.carga_texto}</p>}
                                    <div className="flex flex-wrap gap-1 mt-2 text-[11px]">
                                        {c.sem_cpf > 0 && <span className="rounded-full bg-error-container px-2 py-0.5 text-on-error-container">{c.sem_cpf} sem CPF</span>}
                                        {c.sem_email > 0 && <span className="rounded-full bg-error-container px-2 py-0.5 text-on-error-container">{c.sem_email} sem e-mail</span>}
                                        {c.nomes_corrigidos > 0 && <span className="rounded-full bg-primary-fixed px-2 py-0.5 text-primary">{c.nomes_corrigidos} nome(s) corrigido(s)</span>}
                                        {c.teto_aplicado > 0 && <span className="rounded-full bg-surface-variant px-2 py-0.5 text-on-surface-variant">{c.teto_aplicado} no limite</span>}
                                    </div>
                                </button>
                            ))}
                        </section>

                        <section aria-label="Certificados" className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 md:p-5">
                            <div className="flex flex-wrap items-end gap-3 mb-4">
                                <label className="text-sm min-w-[14rem]">
                                    <span className="block font-semibold mb-1">Categoria</span>
                                    <Select aria-label="Categoria" value={categoria} onChange={(e) => { setCategoria(e.target.value); setPagina(1); }}>
                                        <option value="">Todas as categorias</option>
                                        {resumo.map((c) => <option key={c.chave} value={c.chave}>{c.rotulo}</option>)}
                                    </Select>
                                </label>
                                <label className="text-sm flex-1 min-w-[14rem]">
                                    <span className="block font-semibold mb-1">Buscar</span>
                                    <Input aria-label="Buscar certificado" placeholder="Nome, CPF ou título…" value={busca}
                                        onChange={(e) => { setBusca(e.target.value); setPagina(1); }} />
                                </label>
                            </div>

                            <div className="flex flex-wrap items-center gap-2 mb-4">
                                <span className="text-sm font-semibold text-on-surface mr-1">
                                    Exportar {categoria ? rotuloDe(categoria) : 'todas as categorias'}:
                                </span>
                                {FORMATOS.map(([formato, rotulo, icone]) => (
                                    <Button key={formato} type="button" variant="outline" loading={baixando === formato} onClick={() => baixar(formato)}>
                                        <span className="material-symbols-outlined text-[18px]">{icone}</span>
                                        {rotulo}
                                    </Button>
                                ))}
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="text-left text-xs uppercase text-on-surface-variant">
                                        <tr>
                                            <th className="px-2 py-2">Nome</th>
                                            <th className="px-2 py-2">CPF</th>
                                            <th className="px-2 py-2">Contato</th>
                                            <th className="px-2 py-2">Função</th>
                                            <th className="px-2 py-2">Título do trabalho / atividade</th>
                                            <th className="px-2 py-2">Campo genérico</th>
                                            <th className="px-2 py-2 text-center">Carga</th>
                                            <th className="px-2 py-2">Período</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(linhas ?? []).map((l) => (
                                            <tr key={l.id} className="border-t border-outline-variant/30 align-top">
                                                <td className="px-2 py-2">
                                                    <p className="font-semibold text-on-surface">{l.nome}</p>
                                                    {l.corrigido && <p className="text-xs text-on-surface-variant">No cadastro: {l.nome_original}</p>}
                                                    {!categoria && <p className="text-xs text-on-surface-variant">{rotuloDe(l.categoria)}</p>}
                                                </td>
                                                <td className="px-2 py-2 whitespace-nowrap">
                                                    {l.cpf ? cpfFormatado(l.cpf) : <span className="text-error font-semibold text-xs">sem CPF</span>}
                                                </td>
                                                <td className="px-2 py-2 text-xs">
                                                    <p>{l.email ?? <span className="text-error font-semibold">sem e-mail</span>}</p>
                                                    {l.telefone && <p className="text-on-surface-variant">{l.telefone}</p>}
                                                </td>
                                                <td className="px-2 py-2 text-xs">
                                                    <p>{l.funcao}</p>
                                                    <p className="text-on-surface-variant">{l.tipo_atividade}</p>
                                                </td>
                                                <td className="px-2 py-2 text-xs">{l.titulo}</td>
                                                <td className="px-2 py-2 text-xs">{l.campo_generico ?? '—'}</td>
                                                <td className="px-2 py-2 text-center">
                                                    <p className="font-bold text-secondary">{l.horas}h</p>
                                                    {l.teto_aplicado && <p className="text-[11px] text-on-surface-variant">no limite</p>}
                                                    {l.quantidade !== null && <p className="text-[11px] text-on-surface-variant">× {l.quantidade}</p>}
                                                </td>
                                                <td className="px-2 py-2 text-xs whitespace-nowrap">
                                                    {l.data_inicio ?? '—'}{l.data_fim ? ` a ${l.data_fim}` : ''}
                                                </td>
                                            </tr>
                                        ))}
                                        {linhas?.length === 0 && (
                                            <tr><td colSpan={8} className="px-2 py-6 text-center text-on-surface-variant">Nenhum certificado neste recorte.</td></tr>
                                        )}
                                        {linhas === null && !erro && (
                                            <tr><td colSpan={8} className="px-2 py-6 text-center text-on-surface-variant">Carregando…</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            {meta && meta.ultima_pagina > 1 && (
                                <div className="flex items-center justify-between gap-2 mt-4 text-sm">
                                    <span className="text-on-surface-variant">Página {meta.pagina} de {meta.ultima_pagina} · {meta.total} certificado(s)</span>
                                    <div className="flex gap-2">
                                        <Button type="button" variant="outline" disabled={meta.pagina <= 1} onClick={() => setPagina(meta.pagina - 1)}>Anterior</Button>
                                        <Button type="button" variant="outline" disabled={meta.pagina >= meta.ultima_pagina} onClick={() => setPagina(meta.pagina + 1)}>Próxima</Button>
                                    </div>
                                </div>
                            )}
                            {meta && meta.ultima_pagina <= 1 && (
                                <p className="mt-3 text-xs text-on-surface-variant">{meta.total} certificado(s) neste recorte.</p>
                            )}
                        </section>
                    </>
                )}
            </main>
        </div>
    );
}
