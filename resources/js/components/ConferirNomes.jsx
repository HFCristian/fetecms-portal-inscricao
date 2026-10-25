import { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Input, useConfirm } from './ui.jsx';
import ProjetosDoAvaliador from './ProjetosDoAvaliador.jsx';
import { getPessoasCategoria, salvarNomesCertificados, duracao } from '../lib/certificados.js';
import { extractErrors } from '../lib/auth.jsx';

const POR_VEZ = 100;

const normalizar = (texto) => (texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const cpfFormatado = (cpf) => cpf?.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');

/**
 * "Conferir nomes" (Sprint 259): quem está numa categoria da lista de
 * certificados, com a carga calculada. O lápis ao lado do nome corrige o nome
 * **só nesta lista** — o cadastro da pessoa não muda — e a correção vale em
 * todas as linhas dela (o orientador de dois projetos aparece duas vezes).
 *
 * Salvar grava as correções e fecha; Voltar fecha sem gravar o que ainda não
 * foi salvo. Fechar, por qualquer um dos dois, devolve o controle ao
 * assistente, que grava o passo.
 */
export default function ConferirNomes({ listaId, categoria, onFechar }) {
    const [pessoas, setPessoas] = useState(null);
    const [erro, setErro] = useState('');
    const [busca, setBusca] = useState('');
    const [limite, setLimite] = useState(POR_VEZ);
    const [editando, setEditando] = useState(null);
    const [texto, setTexto] = useState('');
    const [pendentes, setPendentes] = useState({});
    const [projetosDe, setProjetosDe] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [confirm, dialogo] = useConfirm();

    useEffect(() => {
        getPessoasCategoria(listaId, categoria.chave)
            .then(setPessoas)
            .catch(() => setErro('Não foi possível carregar os nomes desta categoria.'));
    }, [listaId, categoria.chave]);

    const nomeDe = (p) => pendentes[p.pessoa_chave] ?? p.nome;

    const filtradas = useMemo(() => {
        const termo = normalizar(busca.trim());
        if (!pessoas) return [];
        if (!termo) return pessoas;
        return pessoas.filter((p) => [nomeDe(p), p.nome_original, p.titulo, p.email].some((v) => normalizar(v).includes(termo)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pessoas, busca, pendentes]);

    const quantasPendentes = Object.keys(pendentes).length;

    function abrirEdicao(p) {
        setEditando(p.pessoa_chave);
        setTexto(nomeDe(p));
    }

    function confirmarEdicao(p) {
        const nome = texto.replace(/\s+/g, ' ').trim();
        setPendentes((atual) => {
            const novo = { ...atual };
            // Voltou ao que já estava salvo: não há o que gravar.
            if (nome === '' || nome === p.nome) delete novo[p.pessoa_chave];
            else novo[p.pessoa_chave] = nome;
            return novo;
        });
        setEditando(null);
    }

    function desfazerCorrecao(p) {
        setPendentes((atual) => ({ ...atual, [p.pessoa_chave]: p.nome_original }));
    }

    async function salvar() {
        if (quantasPendentes === 0) {
            onFechar(false);
            return;
        }
        setSalvando(true); setErro('');
        const originais = Object.fromEntries((pessoas ?? [])
            .filter((p) => p.pessoa_chave in pendentes)
            .map((p) => [p.pessoa_chave, p.nome_original]));
        try {
            await salvarNomesCertificados(listaId, pendentes, originais);
            onFechar(true);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível salvar os nomes.');
            setSalvando(false);
        }
    }

    async function voltar() {
        if (quantasPendentes > 0) {
            const ok = await confirm({
                title: 'Descartar as correções?',
                message: `${quantasPendentes} nome(s) corrigido(s) ainda não foram salvos. Voltar sem salvar descarta essas correções.`,
                confirmLabel: 'Voltar sem salvar',
                danger: true,
            });
            if (!ok) return;
        }
        onFechar(false);
    }

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label={`Conferir nomes — ${categoria.rotulo}`}>
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-3xl p-6 max-h-[90vh] flex flex-col">
                <h3 className="font-display text-xl font-semibold text-on-surface">Conferir nomes</h3>
                <p className="text-sm text-on-surface-variant mb-3">
                    {categoria.rotulo}{pessoas ? ` · ${pessoas.length} certificado(s)` : ''}. A correção vale só nesta
                    lista: o cadastro da pessoa não muda.
                </p>

                <Input aria-label="Buscar nome" placeholder="Buscar por nome, projeto ou e-mail…" value={busca}
                    onChange={(e) => { setBusca(e.target.value); setLimite(POR_VEZ); }} />

                {erro && <div className="mt-3"><Alert>{erro}</Alert></div>}

                <div className="mt-3 overflow-y-auto flex-1 min-h-0 -mx-2 px-2">
                    {pessoas === null && !erro && <p className="py-6 text-center text-sm text-on-surface-variant">Carregando…</p>}
                    {pessoas !== null && filtradas.length === 0 && (
                        <p className="py-6 text-center text-sm text-on-surface-variant">Ninguém {busca ? 'com essa busca' : 'nesta categoria'}.</p>
                    )}
                    <ul className="divide-y divide-outline-variant/30">
                        {filtradas.slice(0, limite).map((p, i) => {
                            const nome = nomeDe(p);
                            const alterado = p.pessoa_chave in pendentes;
                            const corrigido = nome !== p.nome_original;
                            return (
                                <li key={`${p.pessoa_chave}-${p.projeto_id ?? i}`} className="py-2">
                                    <div className="flex items-start gap-2">
                                        <div className="min-w-0 flex-1">
                                            {editando === p.pessoa_chave ? (
                                                <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); confirmarEdicao(p); }}>
                                                    <Input aria-label={`Novo nome de ${p.nome_original}`} value={texto} maxLength={255}
                                                        autoFocus onChange={(e) => setTexto(e.target.value)} />
                                                    <Button type="submit" variant="outline" aria-label="Confirmar nome">
                                                        <span className="material-symbols-outlined text-[18px]">check</span>
                                                    </Button>
                                                    <Button type="button" variant="outline" aria-label="Cancelar edição" onClick={() => setEditando(null)}>
                                                        <span className="material-symbols-outlined text-[18px]">close</span>
                                                    </Button>
                                                </form>
                                            ) : (
                                                <p className="font-semibold text-on-surface">
                                                    {nome}
                                                    {alterado && <span className="ml-2 rounded-full bg-primary-fixed px-2 py-0.5 text-[11px] font-semibold text-primary">não salvo</span>}
                                                </p>
                                            )}
                                            {corrigido && (
                                                <p className="text-xs text-on-surface-variant">
                                                    No cadastro: {p.nome_original}
                                                    <button type="button" className="ml-2 text-primary font-semibold hover:underline" onClick={() => desfazerCorrecao(p)}>
                                                        usar o do cadastro
                                                    </button>
                                                </p>
                                            )}
                                            <p className="text-xs text-on-surface-variant">
                                                {p.cpf ? cpfFormatado(p.cpf) : <span className="text-error font-semibold">sem CPF</span>}
                                                {' · '}{p.email ?? <span className="text-error font-semibold">sem e-mail</span>}
                                                {p.titulo ? ` · ${p.titulo}` : ''}
                                            </p>
                                            <p className="text-xs text-on-surface-variant">
                                                Carga: <strong className="text-on-surface">{p.horas}h</strong>
                                                {p.minutos % 60 !== 0 && ` (${duracao(p.minutos)}, arredondada para cima)`}
                                                {p.teto_aplicado && ' · no limite de horas'}
                                            </p>
                                        </div>
                                        {editando !== p.pessoa_chave && (
                                            <div className="flex flex-col sm:flex-row gap-1 shrink-0">
                                                {p.avaliador_id && (
                                                    <Button type="button" variant="outline" className="!px-3 !py-1.5 text-xs"
                                                        onClick={() => setProjetosDe(projetosDe === p.pessoa_chave ? null : p.pessoa_chave)}>
                                                        Ver projetos avaliados
                                                    </Button>
                                                )}
                                                <Button type="button" variant="outline" className="!px-3 !py-1.5" aria-label={`Editar nome de ${nome}`} onClick={() => abrirEdicao(p)}>
                                                    <span className="material-symbols-outlined text-[18px]">edit</span>
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    {projetosDe === p.pessoa_chave && p.avaliador_id && (
                                        <ProjetosDoAvaliador avaliadorId={p.avaliador_id} onFechar={() => setProjetosDe(null)} />
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                    {filtradas.length > limite && (
                        <div className="flex items-center justify-between gap-2 py-2 text-xs text-on-surface-variant">
                            <span>Mostrando {limite} de {filtradas.length}. Refine a busca ou mostre mais.</span>
                            <button type="button" className="text-primary font-semibold hover:underline" onClick={() => setLimite(limite + POR_VEZ)}>
                                Mostrar mais {Math.min(POR_VEZ, filtradas.length - limite)}
                            </button>
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 pt-4 border-t border-outline-variant/40 mt-2">
                    <span className="text-xs text-on-surface-variant">
                        {quantasPendentes > 0 ? `${quantasPendentes} correção(ões) a salvar` : 'Nenhuma correção pendente'}
                    </span>
                    <div className="flex gap-2">
                        <Button type="button" variant="outline" onClick={voltar}>Voltar</Button>
                        <Button type="button" loading={salvando} onClick={salvar}>
                            <span className="material-symbols-outlined text-[18px]">save</span>
                            Salvar
                        </Button>
                    </div>
                </div>
            </div>
            {dialogo}
        </div>
    );
}
