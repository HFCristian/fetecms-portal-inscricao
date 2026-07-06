import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Input, Button, Alert, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { buscarInstituicoes } from '../lib/catalogos.js';
import { getInstituicoesAdmin, renomearInstituicao, mesclarInstituicao, excluirInstituicao } from '../lib/admin.js';

const TIPO_LABEL = {
    publica_federal: 'Pública Federal',
    publica_estadual: 'Pública Estadual',
    publica_municipal: 'Pública Municipal',
    particular: 'Particular',
};

const inputClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 transition-all outline-none';

function IconBtn({ icon, title, onClick, disabled, danger }) {
    return (
        <button
            type="button" title={title} disabled={disabled} onClick={onClick}
            className={`p-1.5 rounded-lg transition-colors disabled:opacity-30 disabled:cursor-not-allowed ${danger ? 'text-error hover:bg-error-container' : 'text-on-surface-variant hover:bg-surface-variant'}`}
        >
            <span className="material-symbols-outlined text-[20px]">{icon}</span>
        </button>
    );
}

// Pequeno seletor de destino da mescla: busca no servidor (debounce) e devolve uma
// instituição EXISTENTE (exclui a própria origem). Não permite criar — só selecionar.
function DestinoBusca({ origemId, onPick }) {
    const [query, setQuery] = useState('');
    const [resultados, setResultados] = useState([]);
    const [carregando, setCarregando] = useState(false);
    const [open, setOpen] = useState(false);
    const boxRef = useRef(null);

    useEffect(() => {
        function onDocClick(e) { if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false); }
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, []);

    const q = query.trim();
    useEffect(() => {
        if (q.length < 2) { setResultados([]); return undefined; }
        let ativo = true;
        setCarregando(true);
        const t = setTimeout(() => {
            buscarInstituicoes(q)
                .then((r) => { if (ativo) setResultados(r.filter((i) => i.id !== origemId)); })
                .catch(() => { if (ativo) setResultados([]); })
                .finally(() => { if (ativo) setCarregando(false); });
        }, 300);
        return () => { ativo = false; clearTimeout(t); };
    }, [q, origemId]);

    return (
        <div className="relative min-w-[14rem]" ref={boxRef}>
            <input
                type="text" className={inputClass} value={query} placeholder="Buscar destino…"
                onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
                onFocus={() => setOpen(true)}
            />
            {open && q.length >= 2 && (
                <ul className="absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-lg border border-outline-variant bg-surface-container-lowest shadow-lg">
                    {carregando && <li className="px-3 py-2 text-sm text-on-surface-variant">Buscando…</li>}
                    {!carregando && resultados.length === 0 && <li className="px-3 py-2 text-sm text-on-surface-variant">Nada encontrado</li>}
                    {!carregando && resultados.map((i) => (
                        <li key={i.id}>
                            <button
                                type="button"
                                onMouseDown={(e) => { e.preventDefault(); setQuery(i.nome); setOpen(false); onPick(i); }}
                                className="w-full text-left px-3 py-2 text-sm hover:bg-surface-variant"
                            >
                                {i.nome}{i.cidade ? <span className="text-on-surface-variant"> — {i.cidade}</span> : null}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function EscolaRow({ item, onRename, onMerge, onDelete }) {
    const [modo, setModo] = useState('view'); // view | rename | merge
    const [nome, setNome] = useState(item.nome);
    const [destino, setDestino] = useState(null);
    const [busy, setBusy] = useState(false);

    async function executar(fn) {
        setBusy(true);
        try { await fn(); setModo('view'); }
        catch { /* erro exibido no topo da página */ }
        finally { setBusy(false); }
    }

    if (modo === 'rename') {
        return (
            <div className="flex flex-wrap items-center gap-2 py-2">
                <Input value={nome} onChange={(e) => setNome(e.target.value)} />
                <Button type="button" variant="success" loading={busy} onClick={() => executar(() => onRename(item.id, nome.trim()))}>Salvar</Button>
                <Button type="button" variant="outline" onClick={() => { setNome(item.nome); setModo('view'); }}>Cancelar</Button>
            </div>
        );
    }

    if (modo === 'merge') {
        return (
            <div className="flex flex-wrap items-center gap-2 py-2">
                <span className="text-sm text-on-surface-variant">Mesclar <strong>{item.nome}</strong> em:</span>
                <DestinoBusca origemId={item.id} onPick={setDestino} />
                <Button type="button" variant="success" loading={busy} disabled={!destino} onClick={() => executar(() => onMerge(item.id, destino.id))}>Mesclar</Button>
                <Button type="button" variant="outline" onClick={() => { setDestino(null); setModo('view'); }}>Cancelar</Button>
            </div>
        );
    }

    const meta = [item.cidade, TIPO_LABEL[item.tipo] ?? item.tipo].filter(Boolean).join(' · ');

    return (
        <div className="flex items-center gap-3 py-2">
            <div className="flex-1 min-w-0">
                <p className="text-sm text-on-surface truncate">{item.nome}</p>
                {meta && <p className="text-xs text-on-surface-variant truncate">{meta}</p>}
            </div>
            <span className="text-xs text-on-surface-variant shrink-0" title="Usos em projetos, alunos e orientadores">
                {item.usos} uso{item.usos === 1 ? '' : 's'}
            </span>
            <div className="flex items-center gap-1 shrink-0">
                <IconBtn icon="edit" title="Renomear" onClick={() => { setNome(item.nome); setModo('rename'); }} />
                <IconBtn icon="merge" title="Mesclar em outra" onClick={() => { setDestino(null); setModo('merge'); }} />
                <IconBtn icon="delete" title={item.usos === 0 ? 'Excluir' : 'Em uso — mescle antes de excluir'} disabled={item.usos > 0} danger onClick={() => onDelete(item)} />
            </div>
        </div>
    );
}

export default function ParametrizacaoEscolas() {
    const [busca, setBusca] = useState('');
    const [lista, setLista] = useState(null);
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const [confirm, confirmDialog] = useConfirm();

    // Termo efetivamente aplicado na busca (após debounce) — usado também nas mutações
    // para o servidor devolver a lista já filtrada.
    const termoRef = useRef('');

    useEffect(() => {
        const t = setTimeout(() => {
            const termo = busca.trim();
            termoRef.current = termo;
            setLista(null);
            getInstituicoesAdmin(termo).then(setLista).catch(() => setLista([]));
        }, 300);
        return () => clearTimeout(t);
    }, [busca]);

    function aplicar(promise, okMsg) {
        setAlert(''); setSuccess('');
        return promise
            .then((nova) => { setLista(nova); setSuccess(okMsg); })
            .catch((e) => { setAlert(extractErrors(e).message); throw e; });
    }

    const onRename = (id, nome) => aplicar(renomearInstituicao(id, nome, termoRef.current), 'Instituição renomeada.');

    async function onMerge(id, destinoId) {
        const ok = await confirm({ title: 'Mesclar instituições', danger: true, confirmLabel: 'Mesclar',
            message: 'Todas as referências (projetos, alunos e orientadores) serão movidas para a instituição de destino, e esta será excluída. Continuar?' });
        if (ok) return aplicar(mesclarInstituicao(id, destinoId, termoRef.current), 'Instituições mescladas.');
    }
    async function onDelete(item) {
        const ok = await confirm({ title: 'Excluir instituição', danger: true, confirmLabel: 'Excluir', message: `Excluir a instituição “${item.nome}”?` });
        if (ok) aplicar(excluirInstituicao(item.id, termoRef.current), 'Instituição excluída.').catch(() => {});
    }

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Escolas</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Padronize as instituições de ensino. <strong>Mesclar</strong> move todas as referências
                (projetos, alunos e orientadores) para o destino e remove a duplicata. Só é possível
                <strong> excluir</strong> instituições sem uso.
            </p>

            {alert && <div className="mb-4 max-w-3xl"><Alert>{alert}</Alert></div>}
            {success && <div className="mb-4 max-w-3xl"><Alert type="info">{success}</Alert></div>}

            <div className="max-w-3xl">
                <div className="relative mb-4">
                    <span className="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]">search</span>
                    <input
                        type="text"
                        className="w-full bg-surface-container-lowest border border-outline-variant rounded-lg pl-10 pr-3 py-2.5 text-on-surface placeholder:text-outline focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 transition-all outline-none"
                        placeholder="Buscar escola pelo nome…"
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                    />
                </div>

                {lista === null ? (
                    <div className="text-center py-10 text-on-surface-variant"><span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" /></div>
                ) : lista.length === 0 ? (
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm">
                        {busca.trim() ? 'Nenhuma instituição encontrada.' : 'Nenhuma instituição cadastrada.'}
                    </div>
                ) : (
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 divide-y divide-outline-variant/30">
                        {lista.map((item) => (
                            <EscolaRow key={item.id} item={item} onRename={onRename} onMerge={onMerge} onDelete={onDelete} />
                        ))}
                        {lista.length === 50 && (
                            <p className="text-xs text-on-surface-variant pt-3">Mostrando as primeiras 50. Refine a busca para ver outras.</p>
                        )}
                    </div>
                )}
            </div>
            {confirmDialog}
        </AppShell>
    );
}
