import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, useConfirm } from '../components/ui.jsx';
import GrupoArea, { BotoesExpandir, useAreasAbertas } from '../components/GrupoArea.jsx';
import PanoramaAvaliadores from '../components/PanoramaAvaliadores.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getAvaliacaoAvaliadores, definirLimiteAvaliador, definirDemoAvaliador, limparDadosDeTeste } from '../lib/admin.js';

// Métricas do avaliador — também são os critérios de ordenação de cada área.
const METRICAS = [
    { key: 'em_avaliacao', label: 'Em avaliação', cor: 'text-primary-container' },
    { key: 'avaliou', label: 'Já avaliou', cor: 'text-secondary' },
    { key: 'faltam', label: 'Faltam', cor: 'text-on-surface' },
];

function Metrica({ valor, rotulo, cor }) {
    return (
        <div className="text-center w-16">
            <div className={`text-lg font-bold ${cor}`}>{valor}</div>
            <div className="text-[10px] text-on-surface-variant leading-tight">{rotulo}</div>
        </div>
    );
}

// Modal para definir/remover o limite individual do avaliador.
function LimiteModal({ avaliador, onFechar, onSalvar, salvando }) {
    const [valor, setValor] = useState(avaliador.limite ?? '');

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Limitar avaliador</h3>
                    <p className="text-sm text-on-surface-variant truncate">{avaliador.nome}</p>
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface">Máximo de avaliações que pode assumir</label>
                    <input
                        type="number" min="0" max="50" value={valor}
                        onChange={(e) => setValor(e.target.value)}
                        className="w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                    />
                    <p className="text-xs text-on-surface-variant">
                        Ao atingir o limite, o avaliador não poderá assumir novos projetos. Avaliações já em
                        andamento podem ser concluídas mesmo excedendo o limite.
                    </p>
                </div>

                <div className="flex justify-end gap-2 pt-1 flex-wrap">
                    {avaliador.limite != null && (
                        <Button type="button" variant="outline" onClick={() => onSalvar(null)}>Remover limite</Button>
                    )}
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button type="button" loading={salvando} disabled={valor === '' || Number(valor) < 0} onClick={() => onSalvar(Number(valor))}>
                        Salvar
                    </Button>
                </div>
            </div>
        </div>
    );
}

export default function AvaliacaoAvaliadores() {
    const [areas, setAreas] = useState(null);
    const [limitando, setLimitando] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [alert, setAlert] = useState('');
    const [success, setSuccess] = useState('');
    const [confirm, dialogo] = useConfirm();
    const abertas = useAreasAbertas();

    function carregar() {
        return getAvaliacaoAvaliadores().then(setAreas).catch(() => setAreas([]));
    }
    useEffect(() => { carregar(); }, []);

    async function alternarDemo(a) {
        setAlert(''); setSuccess('');
        try {
            const resp = await definirDemoAvaliador(a.id, !a.is_demo);
            setSuccess(resp.meta?.message || 'Atualizado.');
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        }
    }

    async function limparTestes() {
        const ok = await confirm({
            title: 'Limpar dados de teste', danger: true, confirmLabel: 'Limpar',
            message: 'Isso apaga TODAS as avaliações dos avaliadores marcados como demo. Não afeta os avaliadores reais. Continuar?',
        });
        if (!ok) return;
        setAlert(''); setSuccess('');
        try {
            const resp = await limparDadosDeTeste();
            setSuccess(resp.meta?.message || 'Dados de teste limpos.');
            await carregar();
        } catch (e) {
            setAlert(extractErrors(e).message);
        }
    }

    async function salvarLimite(limite) {
        setSalvando(true); setAlert(''); setSuccess('');
        try {
            const resp = await definirLimiteAvaliador(limitando.id, limite);
            setSuccess(resp.meta?.message || 'Limite atualizado.');
            setLimitando(null);
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
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliadores Online</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Panorama do corpo de avaliadores e o progresso de cada avaliador — no máximo 3 projetos por avaliador. Clique na área para
                abrir a lista. Você pode limitar individualmente quantas avaliações cada um pode assumir
                e marcar avaliadores de teste (demo).
            </p>

            <PanoramaAvaliadores />

            <div className="mb-4 max-w-3xl">
                <button
                    type="button"
                    onClick={limparTestes}
                    className="inline-flex items-center gap-1 text-sm font-semibold text-error border border-error/40 rounded-lg px-3 py-1.5 hover:bg-error-container/40 transition-colors"
                >
                    <span className="material-symbols-outlined text-[18px]">delete_sweep</span>
                    Limpar dados de teste
                </button>
            </div>

            {alert && <div className="mb-4 max-w-3xl"><Alert>{alert}</Alert></div>}
            {success && <div className="mb-4 max-w-3xl"><Alert type="info">{success}</Alert></div>}

            {areas === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : areas.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhum avaliador cadastrado ainda.
                </div>
            ) : (
                <>
                    <div className="mb-4 max-w-3xl">
                        <BotoesExpandir ids={areas.map((g) => g.area_id)} controle={abertas} />
                    </div>
                    <div className="space-y-4 max-w-3xl">
                        {areas.map((grupo) => (
                            <GrupoArea
                                key={grupo.area_id}
                                titulo={grupo.area}
                                itens={grupo.avaliadores}
                                singular="avaliador"
                                plural="avaliadores"
                                metricas={METRICAS}
                                ordemPadraoLabel="Nome (A–Z)"
                                aberto={abertas.estaAberto(grupo.area_id)}
                                onToggle={() => abertas.alternar(grupo.area_id)}
                                renderItem={(a) => {
                                    const atingido = a.limite != null && (a.em_avaliacao + a.avaliou) >= a.limite;
                                    return (
                                        <li key={a.id} className="px-4 py-3 flex items-center gap-3 flex-wrap">
                                            <span className="material-symbols-outlined text-primary-container">account_circle</span>
                                            <div className="flex-1 min-w-0 flex items-center gap-2 flex-wrap">
                                                <span className="text-sm text-on-surface truncate">{a.nome}</span>
                                                {a.limite != null && (
                                                    <span
                                                        title={atingido ? 'Limite atingido' : 'Limite definido'}
                                                        className={`text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap ${
                                                            atingido ? 'bg-error-container text-on-error-container' : 'bg-surface-variant text-on-surface-variant'
                                                        }`}
                                                    >
                                                        Limite {a.limite}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-2 shrink-0">
                                                {METRICAS.map((m) => (
                                                    <Metrica key={m.key} valor={a[m.key]} rotulo={m.label} cor={m.cor} />
                                                ))}
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => alternarDemo(a)}
                                                title={a.is_demo ? 'Remover marca de teste (demo)' : 'Marcar como avaliador de teste (demo)'}
                                                className={`shrink-0 inline-flex items-center gap-1 text-xs font-semibold rounded-lg px-2.5 py-1.5 border transition-colors ${
                                                    a.is_demo
                                                        ? 'bg-primary-fixed text-primary-container border-primary-container/30'
                                                        : 'text-on-surface-variant border-outline-variant hover:bg-surface-variant'
                                                }`}
                                            >
                                                <span className="material-symbols-outlined text-[18px]">science</span>
                                                Demo
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setLimitando(a)}
                                                title="Limitar avaliador"
                                                className="shrink-0 p-1.5 rounded-lg text-on-surface-variant hover:bg-surface-variant transition-colors"
                                            >
                                                <span className="material-symbols-outlined text-[20px]">{a.limite != null ? 'lock' : 'lock_open'}</span>
                                            </button>
                                        </li>
                                    );
                                }}
                            />
                        ))}
                    </div>
                </>
            )}

            {limitando && (
                <LimiteModal
                    avaliador={limitando}
                    onFechar={() => setLimitando(null)}
                    onSalvar={salvarLimite}
                    salvando={salvando}
                />
            )}
            {dialogo}
        </AppShell>
    );
}
