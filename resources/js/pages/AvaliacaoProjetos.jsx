import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert } from '../components/ui.jsx';
import BuscaCombobox from '../components/BuscaCombobox.jsx';
import GrupoArea, { BotoesExpandir, compararTexto, useAreasAbertas } from '../components/GrupoArea.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getAvaliacaoProjetos, getAvaliacaoAvaliadores, designarProjeto } from '../lib/admin.js';
import { loadAreas, loadSubareas } from '../lib/catalogos.js';

// Métricas do projeto — também são os critérios de ordenação de cada área.
const METRICAS = [
    { key: 'realizadas', label: 'Realizadas', cor: 'text-secondary' },
    { key: 'em_avaliacao', label: 'Em avaliação', cor: 'text-primary-container' },
    { key: 'faltantes', label: 'Faltantes', cor: 'text-on-surface' },
];

function Metrica({ valor, rotulo, cor }) {
    return (
        <div className="text-center w-16">
            <div className={`text-lg font-bold ${cor}`}>{valor}</div>
            <div className="text-[10px] text-on-surface-variant leading-tight">{rotulo}</div>
        </div>
    );
}

const selectClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

// Modal de designação: avaliador específico, ou todos de uma área/subárea.
// O alvo é escolhido por busca — digite o nome e a lista filtra.
function DesignarModal({ projeto, avaliadores, areas, onFechar, onDesignar, salvando }) {
    const [tipo, setTipo] = useState('avaliador');
    const [alvo, setAlvo] = useState(null);
    const [subareas, setSubareas] = useState([]);

    // Ao escolher "subárea", carrega as subáreas da área do projeto.
    useEffect(() => {
        setAlvo(null);
        if (tipo === 'subarea' && projeto.area_id) {
            loadSubareas(projeto.area_id).then(setSubareas).catch(() => setSubareas([]));
        }
    }, [tipo, projeto.area_id]);

    const opcoes = tipo === 'avaliador' ? avaliadores
        : tipo === 'area' ? areas.map((a) => ({ id: a.id, nome: a.nome }))
            : subareas.map((s) => ({ id: s.id, nome: s.nome }));

    const rotulo = tipo === 'avaliador' ? 'Avaliador' : tipo === 'area' ? 'Área' : 'Subárea';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Designar avaliação</h3>
                    <p className="text-sm text-on-surface-variant truncate">{projeto.titulo}</p>
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="designar-tipo">Designar para</label>
                    <select id="designar-tipo" className={selectClass} value={tipo} onChange={(e) => setTipo(e.target.value)}>
                        <option value="avaliador">Um avaliador específico</option>
                        <option value="area">Todos os avaliadores de uma área</option>
                        <option value="subarea">Todos os avaliadores de uma subárea</option>
                    </select>
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface">{rotulo}</label>
                    <BuscaCombobox
                        options={opcoes}
                        value={alvo}
                        onChange={setAlvo}
                        placeholder={tipo === 'avaliador' ? 'Digite o nome do avaliador…' : `Digite o nome da ${rotulo.toLowerCase()}…`}
                    />
                    {tipo === 'subarea' && subareas.length === 0 && (
                        <p className="text-xs text-on-surface-variant">Nenhuma subárea na área deste projeto.</p>
                    )}
                </div>

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button
                        type="button"
                        loading={salvando}
                        disabled={!alvo}
                        onClick={() => alvo && onDesignar({ tipo, alvo_id: Number(alvo.id) })}
                    >
                        Designar
                    </Button>
                </div>
            </div>
        </div>
    );
}

export default function AvaliacaoProjetos() {
    const [areasProjetos, setAreasProjetos] = useState(null);
    const [avaliadores, setAvaliadores] = useState([]);
    const [areas, setAreas] = useState([]);
    const [designando, setDesignando] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const abertas = useAreasAbertas();

    function carregar() {
        return getAvaliacaoProjetos().then(setAreasProjetos).catch(() => setAreasProjetos([]));
    }

    useEffect(() => {
        carregar();
        // Opções da designação: lista plana de avaliadores em ordem alfabética
        // (o agrupamento por área da API deixaria a busca fora de ordem).
        getAvaliacaoAvaliadores()
            .then((grupos) => setAvaliadores(
                grupos
                    .flatMap((g) => g.avaliadores.map((a) => ({ id: a.id, nome: a.nome, detalhe: g.area })))
                    .sort((a, b) => compararTexto(a.nome, b.nome)),
            ))
            .catch(() => setAvaliadores([]));
        loadAreas().then(setAreas).catch(() => setAreas([]));
    }, []);

    async function designar(payload) {
        setSalvando(true); setAlert(''); setSuccess('');
        try {
            const resp = await designarProjeto(designando.id, payload);
            setSuccess(resp.meta?.message || 'Designação criada.');
            setDesignando(null);
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        } finally {
            setSalvando(false);
        }
    }

    return (
        <AppShell>
            <Link to="/admin/avaliacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação online
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Projetos submetidos</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Projetos submetidos por área, com o progresso das avaliações (mínimo de 3 por projeto).
                Clique na área para abrir a lista. Designe manualmente um projeto para um avaliador ou
                para todos de uma área/subárea.
            </p>

            {alert && <div className="mb-4 max-w-3xl"><Alert>{alert}</Alert></div>}
            {success && <div className="mb-4 max-w-3xl"><Alert type="info">{success}</Alert></div>}

            {areasProjetos === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : areasProjetos.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhum projeto submetido ainda.
                </div>
            ) : (
                <>
                    <div className="mb-4 max-w-3xl">
                        <BotoesExpandir ids={areasProjetos.map((g) => g.area_id)} controle={abertas} />
                    </div>
                    <div className="space-y-4 max-w-3xl">
                        {areasProjetos.map((grupo) => (
                            <GrupoArea
                                key={grupo.area_id}
                                titulo={grupo.area}
                                itens={grupo.projetos}
                                singular="projeto"
                                plural="projetos"
                                rotuloKey="titulo"
                                metricas={METRICAS}
                                ordemPadraoLabel="Título (A–Z)"
                                aberto={abertas.estaAberto(grupo.area_id)}
                                onToggle={() => abertas.alternar(grupo.area_id)}
                                renderItem={(p) => (
                                    <li key={p.id} className="px-4 py-3 flex items-center gap-3 flex-wrap">
                                        <span className="material-symbols-outlined text-primary-container">description</span>
                                        <span className="flex-1 min-w-0 text-sm text-on-surface truncate">{p.titulo}</span>
                                        <div className="flex items-center gap-2 shrink-0">
                                            {METRICAS.map((m) => (
                                                <Metrica key={m.key} valor={p[m.key]} rotulo={m.label} cor={m.cor} />
                                            ))}
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setDesignando({ id: p.id, titulo: p.titulo, area_id: grupo.area_id })}
                                            className="shrink-0 inline-flex items-center gap-1 text-sm font-semibold text-primary-container hover:text-primary border border-outline-variant rounded-lg px-3 py-1.5 hover:bg-surface-variant transition-colors"
                                        >
                                            <span className="material-symbols-outlined text-[18px]">assignment_ind</span>
                                            Designar
                                        </button>
                                    </li>
                                )}
                            />
                        ))}
                    </div>
                </>
            )}

            {designando && (
                <DesignarModal
                    projeto={designando}
                    avaliadores={avaliadores}
                    areas={areas}
                    onFechar={() => setDesignando(null)}
                    onDesignar={designar}
                    salvando={salvando}
                />
            )}
        </AppShell>
    );
}
