import { useEffect, useState } from 'react';
import { Field, Input, Select, Button } from './ui.jsx';
import { loadEstados, loadCidades } from '../lib/catalogos.js';

const TIPOS = [
    { value: 'publica_federal', label: 'Pública Federal' },
    { value: 'publica_estadual', label: 'Pública Estadual' },
    { value: 'publica_municipal', label: 'Pública Municipal' },
    { value: 'particular', label: 'Particular' },
];

/**
 * Diálogo para cadastrar uma instituição nova. Captura a cidade (estado→cidade) para
 * diferenciar instituições de mesmo nome em locais distintos, e o tipo (opcional).
 * onConfirm({ nome, cidade_id, tipo }).
 */
export default function InstituicaoCreateDialog({ open, nomeInicial = '', onCancel, onConfirm, loading = false }) {
    const [nome, setNome] = useState(nomeInicial);
    const [estados, setEstados] = useState([]);
    const [estadoId, setEstadoId] = useState('');
    const [cidades, setCidades] = useState([]);
    const [cidadeId, setCidadeId] = useState('');
    const [tipo, setTipo] = useState('');

    useEffect(() => {
        if (!open) return;
        setNome(nomeInicial); setEstadoId(''); setCidadeId(''); setCidades([]); setTipo('');
        loadEstados().then(setEstados).catch(() => setEstados([]));
    }, [open, nomeInicial]);

    async function onEstado(id) {
        setEstadoId(id);
        setCidadeId('');
        setCidades(id ? await loadCidades(id) : []);
    }

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <h3 className="font-display text-xl font-semibold text-on-surface">Cadastrar instituição</h3>
                <p className="text-sm text-on-surface-variant">
                    Informe a cidade para diferenciar instituições de mesmo nome em locais distintos.
                </p>

                <Field label="Nome" required>
                    <Input value={nome} onChange={(e) => setNome(e.target.value)} />
                </Field>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <Field label="Estado">
                        <Select value={estadoId} onChange={(e) => onEstado(e.target.value)}>
                            <option value="">Selecione</option>
                            {estados.map((es) => <option key={es.id} value={es.id}>{es.nome} ({es.uf})</option>)}
                        </Select>
                    </Field>
                    <Field label="Cidade">
                        <Select value={cidadeId} onChange={(e) => setCidadeId(e.target.value)} disabled={!estadoId}>
                            <option value="">{estadoId ? 'Selecione' : 'Escolha o estado'}</option>
                            {cidades.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                        </Select>
                    </Field>
                </div>
                <Field label="Tipo (opcional)">
                    <Select value={tipo} onChange={(e) => setTipo(e.target.value)}>
                        <option value="">Selecione</option>
                        {TIPOS.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </Select>
                </Field>

                <div className="flex justify-end gap-3 pt-1">
                    <Button type="button" variant="outline" onClick={onCancel} disabled={loading}>Cancelar</Button>
                    <Button
                        type="button" variant="success" loading={loading} disabled={!nome.trim()}
                        onClick={() => onConfirm({ nome: nome.trim(), cidade_id: cidadeId || null, tipo: tipo || null })}
                    >
                        Cadastrar
                    </Button>
                </div>
            </div>
        </div>
    );
}
