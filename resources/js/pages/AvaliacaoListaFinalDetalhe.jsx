import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert } from '../components/ui.jsx';
import BuscaCombobox from '../components/BuscaCombobox.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getListaFinal, adicionarNaListaFinal, removerDaListaFinal, baixarListaOficial,
} from '../lib/admin.js';

const MIN_JUSTIFICATIVA = 5;

/**
 * Diálogo de justificativa das alterações na lista oficial. Incluir ou retirar
 * um projeto é uma decisão fora do recorte por nota, então cada uma precisa
 * ficar explicada em Registros → Lista final.
 */
function JustificativaDialog({ titulo, projeto, acao, salvando, erro, onConfirmar, onFechar }) {
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
                        Fica em Registros → Lista final, junto do seu nome. Uma versão nova do arquivo é gerada.
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

/**
 * Composição de uma lista final oficial: quem está dentro e quem pode entrar.
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
            const novo = dialogo.tipo === 'incluir'
                ? await adicionarNaListaFinal(id, dialogo.projeto.id, justificativa)
                : await removerDaListaFinal(id, dialogo.projeto.id, justificativa);
            setDados(novo);
            setDialogo(null);
            setCandidato(null);
        } catch (e) {
            setErroDialogo(extractErrors(e).message || 'Não foi possível concluir.');
        } finally {
            setSalvando(false);
        }
    }

    const lista = dados?.lista;
    const itens = dados?.itens ?? [];
    // O combobox espera { id, nome, detalhe } e faz a busca por conta própria.
    const candidatos = (dados?.candidatos ?? []).map((c) => ({
        id: c.id,
        nome: c.titulo,
        detalhe: [c.categoria, c.area, c.media !== null ? `média ${c.media}` : null]
            .filter(Boolean).join(' · '),
    }));

    return (
        <AppShell>
            <Link to="/admin/avaliacao/listas-finais" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Listas finais oficiais
            </Link>

            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

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
                                {lista.vigente && (
                                    <span className="ml-2 align-middle text-xs font-semibold px-2 py-0.5 rounded-full bg-secondary-container text-on-secondary-container">
                                        vigente
                                    </span>
                                )}
                            </h1>
                            <p className="text-sm text-on-surface-variant">
                                Versão {lista.versao} · {lista.projetos} {lista.projetos === 1 ? 'projeto' : 'projetos'}.
                                Cada alteração exige justificativa e gera um arquivo novo.
                            </p>
                        </div>
                        <Button type="button" variant="outline" className="shrink-0" onClick={() => baixarListaOficial(id)}>
                            <span className="material-symbols-outlined text-[20px]">download</span>
                            Baixar TXT
                        </Button>
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
                                    placeholder="Buscar entre os projetos avaliados fora da lista…"
                                    vazio="Nenhum projeto avaliado fora da lista"
                                />
                            </div>
                            <Button
                                type="button"
                                disabled={!candidato}
                                onClick={() => {
                                    setErroDialogo('');
                                    setDialogo({ tipo: 'incluir', projeto: { id: candidato.id, titulo: candidato.nome } });
                                }}
                            >
                                <span className="material-symbols-outlined text-[20px]">playlist_add</span>
                                Incluir
                            </Button>
                        </div>
                    </div>

                    {/* Composição */}
                    {itens.length === 0 ? (
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm max-w-3xl">
                            A lista está vazia.
                        </div>
                    ) : (
                        <ul className="bg-surface-container-lowest rounded-xl fetec-card-shadow divide-y divide-outline-variant/40 max-w-3xl">
                            {itens.map((i) => (
                                <li key={i.projeto_id} className="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold text-on-surface">
                                            <span className="font-mono text-xs text-on-surface-variant mr-2">{i.codigo}</span>
                                            {i.titulo}
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
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="text-error border-error/40 hover:bg-error-container/40"
                                        onClick={() => {
                                            setErroDialogo('');
                                            setDialogo({ tipo: 'remover', projeto: { id: i.projeto_id, titulo: i.titulo } });
                                        }}
                                    >
                                        <span className="material-symbols-outlined text-[20px]">playlist_remove</span>
                                        Retirar
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}

            {dialogo && (
                <JustificativaDialog
                    titulo={dialogo.tipo === 'incluir' ? 'Incluir na lista final' : 'Retirar da lista final'}
                    projeto={dialogo.projeto.titulo}
                    acao={dialogo.tipo === 'incluir' ? 'Incluir' : 'Retirar'}
                    salvando={salvando}
                    erro={erroDialogo}
                    onConfirmar={confirmar}
                    onFechar={() => setDialogo(null)}
                />
            )}
        </AppShell>
    );
}
