import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Input, Select, Button, Alert, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getCatalogo, renomearArea, mesclarArea, excluirArea,
    renomearSubarea, mesclarSubarea, excluirSubarea,
} from '../lib/admin.js';

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

// Linha de área OU subárea, com modos inline: renomear e mesclar. `irmaos` são os
// destinos válidos de mescla (outras áreas, ou outras subáreas da mesma área).
function ItemRow({ item, irmaos, isArea = false, onRename, onMerge, onDelete }) {
    const [modo, setModo] = useState('view'); // view | rename | merge
    const [nome, setNome] = useState(item.nome);
    const [destino, setDestino] = useState('');
    const [busy, setBusy] = useState(false);

    const candidatos = irmaos.filter((x) => x.id !== item.id);
    const podeExcluir = item.usos === 0 && (!isArea || (item.subareas?.length ?? 0) === 0);

    async function executar(fn) {
        setBusy(true);
        try { await fn(); setModo('view'); }
        catch { /* erro já exibido no topo da página */ }
        finally { setBusy(false); }
    }

    if (modo === 'rename') {
        return (
            <div className="flex flex-wrap items-center gap-2 py-1.5">
                <Input value={nome} onChange={(e) => setNome(e.target.value)} />
                <Button type="button" variant="success" loading={busy} onClick={() => executar(() => onRename(item.id, nome.trim()))}>Salvar</Button>
                <Button type="button" variant="outline" onClick={() => { setNome(item.nome); setModo('view'); }}>Cancelar</Button>
            </div>
        );
    }

    if (modo === 'merge') {
        return (
            <div className="flex flex-wrap items-center gap-2 py-1.5">
                <span className="text-sm text-on-surface-variant">Mesclar <strong>{item.nome}</strong> em:</span>
                <Select value={destino} onChange={(e) => setDestino(e.target.value)}>
                    <option value="">Selecione o destino</option>
                    {candidatos.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                </Select>
                <Button type="button" variant="success" loading={busy} disabled={!destino} onClick={() => executar(() => onMerge(item.id, destino))}>Mesclar</Button>
                <Button type="button" variant="outline" onClick={() => { setDestino(''); setModo('view'); }}>Cancelar</Button>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-3 py-1.5">
            <span className={`flex-1 min-w-0 truncate ${isArea ? 'font-semibold text-on-surface' : 'text-sm text-on-surface'}`}>{item.nome}</span>
            <span className="text-xs text-on-surface-variant shrink-0" title="Usos em projetos, avaliadores e orientadores">
                {item.usos} uso{item.usos === 1 ? '' : 's'}
            </span>
            <div className="flex items-center gap-1 shrink-0">
                <IconBtn icon="edit" title="Renomear" onClick={() => { setNome(item.nome); setModo('rename'); }} />
                {candidatos.length > 0 && <IconBtn icon="merge" title="Mesclar em outra" onClick={() => { setDestino(''); setModo('merge'); }} />}
                <IconBtn icon="delete" title={podeExcluir ? 'Excluir' : 'Em uso — mescle antes de excluir'} disabled={!podeExcluir} danger onClick={() => onDelete(item)} />
            </div>
        </div>
    );
}

export default function ParametrizacaoAreas() {
    const [arvore, setArvore] = useState(null);
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const [confirm, confirmDialog] = useConfirm();

    useEffect(() => { getCatalogo().then(setArvore).catch(() => setArvore([])); }, []);

    // Aplica uma mutação: substitui a árvore pela versão atualizada do servidor.
    function aplicar(promise, okMsg) {
        setAlert(''); setSuccess('');
        return promise
            .then((nova) => { setArvore(nova); setSuccess(okMsg); })
            .catch((e) => { setAlert(extractErrors(e).message); throw e; });
    }

    const onRenameArea = (id, nome) => aplicar(renomearArea(id, nome), 'Área renomeada.');
    const onRenameSub = (id, nome) => aplicar(renomearSubarea(id, nome), 'Subárea renomeada.');

    async function onMergeArea(id, destinoId) {
        const ok = await confirm({ title: 'Mesclar áreas', danger: true, confirmLabel: 'Mesclar',
            message: 'As subáreas e todas as referências (projetos, avaliadores e orientadores) serão movidas para a área de destino, e esta área será excluída. Continuar?' });
        if (ok) return aplicar(mesclarArea(id, destinoId), 'Áreas mescladas.');
    }
    async function onMergeSub(id, destinoId) {
        const ok = await confirm({ title: 'Mesclar subáreas', danger: true, confirmLabel: 'Mesclar',
            message: 'Todas as referências serão movidas para a subárea de destino, e esta subárea será excluída. Continuar?' });
        if (ok) return aplicar(mesclarSubarea(id, destinoId), 'Subáreas mescladas.');
    }
    async function onDeleteArea(item) {
        const ok = await confirm({ title: 'Excluir área', danger: true, confirmLabel: 'Excluir', message: `Excluir a área “${item.nome}”?` });
        if (ok) aplicar(excluirArea(item.id), 'Área excluída.').catch(() => {});
    }
    async function onDeleteSub(item) {
        const ok = await confirm({ title: 'Excluir subárea', danger: true, confirmLabel: 'Excluir', message: `Excluir a subárea “${item.nome}”?` });
        if (ok) aplicar(excluirSubarea(item.id), 'Subárea excluída.').catch(() => {});
    }

    const areas = arvore ?? [];

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Áreas e subáreas</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Gerencie o catálogo de áreas e subáreas. <strong>Mesclar</strong> move todas as referências
                (projetos, avaliadores e orientadores) para o destino e remove a original. Só é possível
                <strong> excluir</strong> itens sem uso.
            </p>

            {alert && <div className="mb-4 max-w-3xl"><Alert>{alert}</Alert></div>}
            {success && <div className="mb-4 max-w-3xl"><Alert type="info">{success}</Alert></div>}

            {arvore === null ? (
                <div className="text-center py-10 text-on-surface-variant"><span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" /></div>
            ) : areas.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-on-surface-variant text-sm max-w-3xl">Nenhuma área cadastrada.</div>
            ) : (
                <div className="space-y-4 max-w-3xl">
                    {areas.map((area) => (
                        <div key={area.id} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <ItemRow item={area} irmaos={areas} isArea onRename={onRenameArea} onMerge={onMergeArea} onDelete={onDeleteArea} />
                            <div className="mt-2 pl-4 border-l-2 border-outline-variant/40">
                                {area.subareas.length === 0 ? (
                                    <p className="text-xs text-on-surface-variant py-1">Sem subáreas.</p>
                                ) : area.subareas.map((sub) => (
                                    <ItemRow key={sub.id} item={sub} irmaos={area.subareas} onRename={onRenameSub} onMerge={onMergeSub} onDelete={onDeleteSub} />
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}
            {confirmDialog}
        </AppShell>
    );
}
