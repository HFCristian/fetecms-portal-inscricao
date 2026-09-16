import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getOpcoesDesignacaoComite, designarPeloComite } from '../lib/comite.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

/** Uma lista de caixas de marcação, com busca no servidor. */
function Lista({ titulo, itens, marcados, onAlternar, busca, onBuscar, placeholder, vazio, detalhe }) {
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
    const [buscaAvaliador, setBuscaAvaliador] = useState('');
    const [projetos, setProjetos] = useState([]);
    const [avaliadores, setAvaliadores] = useState([]);
    const [salvando, setSalvando] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [resultado, setResultado] = useState(null);

    const carregar = useCallback(() => getOpcoesDesignacaoComite({
        projeto: buscaProjeto.trim(),
        avaliador: buscaAvaliador.trim(),
    })
        .then(setOpcoes)
        .catch(() => setAlerta('Não foi possível carregar projetos e avaliadores.')), [buscaProjeto, buscaAvaliador]);

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
                    placeholder="Buscar projeto"
                    vazio="Nenhum projeto encontrado."
                    detalhe={(p) => [p.area, p.categoria, `${p.concluidas} avaliação(ões)`].filter(Boolean).join(' · ')}
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
        </AppShell>
    );
}
