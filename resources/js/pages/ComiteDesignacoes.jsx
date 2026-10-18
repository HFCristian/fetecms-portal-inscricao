import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getOpcoesDesignacaoComite, designarPeloComite } from '../lib/comite.js';
import PedidosPermissaoComite from '../components/PedidosPermissaoComite.jsx';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const FILTROS_VAZIOS = { codigo: '', estande: '', turno: '' };

/** O número do estande como na planta: três dígitos. */
const estande = (n) => String(n).padStart(3, '0');

/** Uma lista de caixas de marcação, com busca no servidor. */
function Lista({ titulo, itens, marcados, onAlternar, busca, onBuscar, placeholder, vazio, detalhe, filtros = null }) {
    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
            <h2 className="font-display font-semibold text-on-surface mb-2">{titulo}</h2>
            <input
                className={`${campoClass} mb-3`}
                placeholder={placeholder}
                aria-label={placeholder}
                value={busca}
                onChange={(e) => onBuscar(e.target.value)}
            />
            {filtros}
            {itens.length === 0 ? (
                <p className="text-sm text-on-surface-variant">{vazio}</p>
            ) : (
                <ul className="max-h-72 overflow-y-auto divide-y divide-outline-variant/30">
                    {itens.map((item) => (
                        <li key={item.id}>
                            <label className="flex items-start gap-2 py-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="mt-1 w-4 h-4 rounded text-primary-container"
                                    checked={marcados.includes(item.id)}
                                    onChange={() => onAlternar(item.id)}
                                    aria-label={item.nome ?? item.titulo}
                                />
                                <span className="min-w-0">
                                    <span className="block text-sm text-on-surface">{item.nome ?? item.titulo}</span>
                                    <span className="block text-xs text-on-surface-variant">{detalhe(item)}</span>
                                </span>
                            </label>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Filtros do local do projeto no evento: código da lista final, número do
 * estande e turno. Com qualquer um preenchido, só aparecem finalistas — os
 * demais projetos não têm local.
 */
function FiltrosLocal({ filtros, onMudar, turnos }) {
    const algum = Object.values(filtros).some(Boolean);
    const mudar = (campo) => (e) => onMudar({ ...filtros, [campo]: e.target.value });

    return (
        <div className="mb-3">
            <div className="grid grid-cols-3 gap-2">
                <label className="text-xs text-on-surface-variant">
                    Código
                    <input
                        className={`${campoClass} mt-1`}
                        placeholder="FET.AGR"
                        value={filtros.codigo}
                        onChange={mudar('codigo')}
                    />
                </label>
                <label className="text-xs text-on-surface-variant">
                    Estande
                    <input
                        className={`${campoClass} mt-1`}
                        inputMode="numeric"
                        placeholder="042"
                        value={filtros.estande}
                        onChange={(e) => onMudar({ ...filtros, estande: e.target.value.replace(/\D/g, '').slice(0, 4) })}
                    />
                </label>
                <label className="text-xs text-on-surface-variant">
                    Turno
                    <select className={`${campoClass} mt-1`} value={filtros.turno} onChange={mudar('turno')}>
                        <option value="">Todos</option>
                        {turnos.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </select>
                </label>
            </div>
            {algum && (
                <div className="flex items-center justify-between gap-2 mt-2 text-xs text-on-surface-variant">
                    <span>Com filtro de local, só aparecem os finalistas da lista final vigente.</span>
                    <button type="button" className="text-primary font-semibold hover:underline shrink-0" onClick={() => onMudar(FILTROS_VAZIOS)}>
                        Limpar filtros
                    </button>
                </div>
            )}
        </div>
    );
}

/**
 * Comitê especial → **Designações**.
 *
 * Aqui o admin do comitê designa projetos **só** para os avaliadores da
 * comissão especial. É uma tela própria, e não um filtro na de Avaliação
 * online, porque quem cuida do comitê costuma ter apenas essa aba no escopo — e
 * porque a pergunta é outra: "quem do comitê vê este projeto?", e não "como
 * cobrir a feira".
 */
export default function ComiteDesignacoes() {
    const [opcoes, setOpcoes] = useState({ projetos: [], avaliadores: [] });
    const [buscaProjeto, setBuscaProjeto] = useState('');
    const [filtros, setFiltros] = useState(FILTROS_VAZIOS);
    const [buscaAvaliador, setBuscaAvaliador] = useState('');
    const [projetos, setProjetos] = useState([]);
    const [avaliadores, setAvaliadores] = useState([]);
    const [salvando, setSalvando] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [resultado, setResultado] = useState(null);

    const carregar = useCallback(() => getOpcoesDesignacaoComite({
        projeto: buscaProjeto.trim(),
        avaliador: buscaAvaliador.trim(),
        codigo: filtros.codigo.trim(),
        estande: filtros.estande,
        turno: filtros.turno,
    })
        .then(setOpcoes)
        .catch(() => setAlerta('Não foi possível carregar projetos e avaliadores.')), [buscaProjeto, buscaAvaliador, filtros]);

    useEffect(() => {
        const t = setTimeout(carregar, 300);

        return () => clearTimeout(t);
    }, [carregar]);

    const alternar = (lista, set) => (id) =>
        set(lista.includes(id) ? lista.filter((x) => x !== id) : [...lista, id]);

    const total = projetos.length * avaliadores.length;

    async function designar() {
        setSalvando(true);
        setAlerta('');
        try {
            const resp = await designarPeloComite(projetos, avaliadores);
            setResultado(resp.data);
            setProjetos([]);
            setAvaliadores([]);
            await carregar();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível designar.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <AppShell>
            <Link to="/admin/comite" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Comitê especial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Designações do comitê</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Projetos para os avaliadores da <strong>comissão especial</strong> — e só para eles.
                Cada avaliador marcado recebe <strong>todos</strong> os projetos marcados; quem já
                avaliou um projeto é pulado, e a tela diz quais foram.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}

            {resultado && (
                <div className="mb-4 max-w-3xl space-y-2">
                    <Alert type="info">{resultado.resumo}</Alert>
                    {(resultado.ignoradas ?? []).length > 0 && (
                        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                            <p className="text-sm font-semibold text-on-surface mb-1">
                                {resultado.ignoradas.length} designação(ões) não foram feitas:
                            </p>
                            <ul className="text-xs text-on-surface-variant list-disc pl-4 space-y-0.5">
                                {resultado.ignoradas.map((motivo, i) => <li key={i}>{motivo}</li>)}
                            </ul>
                        </div>
                    )}
                </div>
            )}

            <div className="grid gap-4 md:grid-cols-2 max-w-4xl">
                <Lista
                    titulo="Projetos submetidos"
                    itens={opcoes.projetos}
                    marcados={projetos}
                    onAlternar={alternar(projetos, setProjetos)}
                    busca={buscaProjeto}
                    onBuscar={setBuscaProjeto}
                    placeholder="Buscar por título, código, estande ou turno"
                    vazio="Nenhum projeto encontrado."
                    filtros={<FiltrosLocal filtros={filtros} onMudar={setFiltros} turnos={opcoes.turnos ?? []} />}
                    detalhe={(p) => [
                        p.codigo,
                        p.estande ? `Estande ${estande(p.estande)}` : null,
                        p.turno_label,
                        p.area,
                        p.categoria,
                        `${p.concluidas} avaliação(ões)`,
                    ].filter(Boolean).join(' · ')}
                />
                <Lista
                    titulo="Avaliadores da comissão especial"
                    itens={opcoes.avaliadores}
                    marcados={avaliadores}
                    onAlternar={alternar(avaliadores, setAvaliadores)}
                    busca={buscaAvaliador}
                    onBuscar={setBuscaAvaliador}
                    placeholder="Buscar avaliador"
                    vazio="Nenhum avaliador da comissão especial. Marque alguém em Avaliação online → Avaliadores."
                    detalhe={(a) => [a.area, `${a.na_fila} na fila`].filter(Boolean).join(' · ')}
                />
            </div>

            <div className="flex items-center justify-end gap-3 mt-4 max-w-4xl">
                <span className="text-sm text-on-surface-variant">
                    {projetos.length} projeto(s) × {avaliadores.length} avaliador(es) = <strong>{total}</strong>
                </span>
                <Button type="button" loading={salvando} disabled={total === 0} onClick={designar}>
                    <span className="material-symbols-outlined text-[20px]">person_add</span>
                    Designar
                </Button>
            </div>

            {/* Logo abaixo da designação: os pedidos para avaliar fora dela (Sprint 234). */}
            <PedidosPermissaoComite />
        </AppShell>
    );
}
