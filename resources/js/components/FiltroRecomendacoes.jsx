import { useState } from 'react';
import { Button, Input, Select } from './ui.jsx';

export const SEM_RECORTE = { q: '', area_id: '', categoria: '' };

/**
 * A barra de filtros das recomendações do comitê (Sprints 194 e 201): fica no
 * topo da tela e recorta as recomendações mostradas **dentro dos cartões**
 * das credenciais — busca pelo título ou pelo código do projeto, área e
 * categoria. Os cartões e quem já recebeu cada credencial aparecem sempre.
 */
export default function FiltroRecomendacoes({ valor, onChange, opcoes, pendentes }) {
    const [busca, setBusca] = useState(valor.q);
    const recortando = valor.q !== '' || valor.area_id !== '' || valor.categoria !== '';
    const areas = opcoes?.areas ?? [];
    const categorias = opcoes?.categorias ?? [];

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-4 max-w-3xl" aria-label="Filtrar recomendações do comitê">
            <div className="flex flex-wrap items-baseline justify-between gap-2 mb-2">
                <h2 className="font-display text-base font-semibold text-on-surface">Recomendações do comitê</h2>
                <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${pendentes > 0 ? 'bg-yellow-100 text-yellow-900' : 'bg-surface-variant text-on-surface-variant'}`}>
                    {pendentes} pedido(s) de aprovação{recortando ? ' no filtro' : ''}
                </span>
            </div>
            <p className="text-xs text-on-surface-variant mb-3">
                Os pedidos aparecem no cartão de cada credencial. Filtre para ver só os de um projeto, área ou categoria.
            </p>
            <form
                className="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_11rem_11rem] gap-2"
                onSubmit={(e) => { e.preventDefault(); onChange({ ...valor, q: busca.trim() }); }}
            >
                <div className="flex gap-2">
                    <Input
                        aria-label="Buscar pelo título ou código do projeto"
                        placeholder="Título ou código (FET.AGR-001)"
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                    />
                    <Button type="submit" variant="outline">Buscar</Button>
                </div>
                <Select aria-label="Área do conhecimento" value={valor.area_id} onChange={(e) => onChange({ ...valor, area_id: e.target.value })}>
                    <option value="">Todas as áreas</option>
                    {areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                </Select>
                <Select aria-label="Categoria" value={valor.categoria} onChange={(e) => onChange({ ...valor, categoria: e.target.value })}>
                    <option value="">Todas as categorias</option>
                    {categorias.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
                </Select>
            </form>
            {recortando && (
                <button
                    type="button"
                    className="text-xs text-primary hover:underline mt-2"
                    onClick={() => { setBusca(''); onChange(SEM_RECORTE); }}
                >
                    Limpar filtros
                </button>
            )}
        </section>
    );
}
