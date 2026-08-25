import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Toggle, Button, useConfirm } from '../components/ui.jsx';
import AvaliacaoModal from '../components/AvaliacaoModal.jsx';
import { useAuth } from '../lib/auth.jsx';
import { getMinhaAvaliacao, roletarFila } from '../lib/avaliacao.js';

const PILL = {
    designada: 'bg-surface-variant text-on-surface-variant',
    em_andamento: 'bg-primary-fixed text-primary-container',
    concluida: 'bg-secondary text-on-secondary',
};

/** A nota final é a soma ponderada da rubrica (0 a 10), com duas casas. */
const formatarNota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

function botaoLabel(status) {
    if (status === 'em_andamento') return 'Continuar';
    if (status === 'concluida') return 'Ver';
    return 'Avaliar';
}

// Uma lista de projetos (a fila de trabalho ou o histórico de avaliados).
function ListaProjetos({ titulo, itens, dados, vazio, onAbrir }) {
    return (
        <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden max-w-3xl">
            <div className="px-4 py-3 bg-surface-variant/40">
                <h2 className="font-display font-semibold text-on-surface">{titulo}</h2>
            </div>
            {itens.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-on-surface-variant">{vazio}</p>
            ) : (
                <ul className="divide-y divide-outline-variant/30">
                    {itens.map((p) => (
                        <li key={p.avaliacao_id} className="px-4 py-3 flex items-center gap-3">
                            <span className="material-symbols-outlined text-primary-container">
                                {p.status === 'concluida' ? 'task_alt' : 'description'}
                            </span>
                            <div className="flex-1 min-w-0">
                                <p className="text-sm text-on-surface truncate">{p.titulo}</p>
                                <p className="text-xs text-on-surface-variant truncate">
                                    {p.area}
                                    {p.area && p.concluida_em_label ? ' · ' : ''}
                                    {p.concluida_em_label ? `avaliado em ${p.concluida_em_label}` : ''}
                                </p>
                            </div>
                            {p.status === 'concluida' && (
                                <span className="text-xs text-on-surface-variant shrink-0">
                                    nota <strong className="text-secondary">{formatarNota(p.nota)}</strong>
                                    <span className="text-on-surface-variant/70">/{dados.nota_maxima}</span>
                                </span>
                            )}
                            <span className={`text-xs font-semibold px-2 py-0.5 rounded-full shrink-0 ${PILL[p.status] ?? 'bg-surface-variant'}`}>
                                {p.status_label}
                            </span>
                            <button
                                type="button"
                                onClick={() => onAbrir(p.avaliacao_id)}
                                className="shrink-0 text-sm font-semibold text-primary-container hover:text-primary border border-outline-variant rounded-lg px-3 py-1.5 hover:bg-surface-variant transition-colors"
                            >
                                {dados.pode_avaliar ? botaoLabel(p.status) : 'Ver'}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

// Abas "A avaliar" / "Avaliados": o que já foi enviado sai da fila de trabalho.
function Abas({ aba, setAba, pendentes, concluidos }) {
    const abas = [
        { chave: 'pendentes', rotulo: 'A avaliar', total: pendentes },
        { chave: 'concluidos', rotulo: 'Avaliados', total: concluidos },
    ];

    return (
        <div className="flex gap-2 mb-4 max-w-3xl" role="tablist">
            {abas.map((t) => (
                <button
                    key={t.chave}
                    type="button"
                    role="tab"
                    aria-selected={aba === t.chave}
                    onClick={() => setAba(t.chave)}
                    className={`text-sm font-semibold px-4 py-2 rounded-lg border transition-colors ${
                        aba === t.chave
                            ? 'bg-primary-container text-on-primary border-primary-container'
                            : 'bg-surface-container-lowest text-on-surface-variant border-outline-variant hover:bg-surface-variant'
                    }`}
                >
                    {t.rotulo} ({t.total})
                </button>
            ))}
        </div>
    );
}

export default function AvaliadorHome() {
    const { user } = useAuth();
    const sub = user?.avaliador_profile?.subarea;
    const area = user?.avaliador_profile?.area;

    const [dados, setDados] = useState(null);
    // Demo já entra em "modo teste" (ignora a data). Para avaliador real, o backend
    // ignora o flag — ele continua travado pela data. O toggle só aparece para demo.
    const [modoTeste, setModoTeste] = useState(true);
    const [avaliando, setAvaliando] = useState(null); // avaliacao_id em avaliação
    const [aba, setAba] = useState('pendentes');
    const [sorteando, setSorteando] = useState(false);
    const [avisoSorteio, setAvisoSorteio] = useState('');
    const [confirm, confirmDialog] = useConfirm();

    const carregar = useCallback((teste) => {
        return getMinhaAvaliacao(teste)
            .then(setDados)
            .catch(() => setDados({
                liberada: false, pode_ver: false, pode_avaliar: false, is_demo: false,
                projetos: [], concluidos: [],
            }));
    }, []);

    useEffect(() => { carregar(modoTeste); }, [carregar, modoTeste]);

    // Sorteia outra fila. Só mexe no que ainda não foi aberto e não veio do admin.
    async function sortear() {
        const ok = await confirm({
            title: 'Sortear outros projetos',
            confirmLabel: 'Sortear',
            message: 'Os projetos que você ainda não abriu voltam para a organização e outros entram no lugar. '
                + 'O que já está em avaliação e o que foi designado pela organização continuam na sua lista.',
        });
        if (!ok) return;

        setSorteando(true);
        setAvisoSorteio('');
        try {
            const resp = await roletarFila(modoTeste && dados?.is_demo);
            await carregar(modoTeste);
            setAvisoSorteio(resp.meta?.message || 'Fila sorteada de novo.');
        } catch {
            setAvisoSorteio('Não foi possível sortear agora. Tente novamente.');
        } finally {
            setSorteando(false);
        }
    }

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Painel do Avaliador</h1>
            <p className="text-on-surface-variant mb-4">
                {area ? <>Sua área: <strong>{area}</strong>{sub ? <> · subárea <strong>{sub}</strong></> : null}.</> : 'Bem-vindo(a).'}
            </p>

            {dados?.is_demo && (
                <div className="bg-primary-fixed/60 border border-primary-container/30 rounded-xl p-4 mb-4 max-w-3xl flex items-start gap-3">
                    <span className="material-symbols-outlined text-primary-container">science</span>
                    <div className="flex-1">
                        <Toggle
                            checked={modoTeste}
                            onChange={setModoTeste}
                            label="Modo de teste"
                            description="Avaliador demo: teste o fluxo completo mesmo antes da liberação. As avaliações são de teste e podem ser limpas pelo admin."
                        />
                    </div>
                </div>
            )}

            {/* Período encerrado: a leitura continua, a escrita não. */}
            {dados?.pode_ver && !dados.pode_avaliar && (
                <div className="mb-4 max-w-3xl">
                    <Alert>
                        O período de avaliação foi encerrado
                        {dados.encerrada_em_label ? <> em <strong>{dados.encerrada_em_label}</strong></> : null}. Você
                        ainda pode abrir os projetos e conferir o que respondeu, mas não é mais possível iniciar,
                        salvar ou enviar avaliações.
                    </Alert>
                </div>
            )}

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : !dados.pode_ver ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-10 text-center max-w-3xl">
                    <span className="material-symbols-outlined text-[48px] text-primary-container">event_upcoming</span>
                    <p className="text-on-surface mt-3 font-semibold">As avaliações ainda não foram liberadas</p>
                    <p className="text-on-surface-variant text-sm mt-1 max-w-md mx-auto">
                        {dados.liberada_em_label
                            ? <>Serão liberadas em <strong>{dados.liberada_em_label}</strong>. Os projetos designados para você aparecerão aqui.</>
                            : 'A partir da data definida pela organização, os projetos designados aparecerão aqui para leitura e avaliação.'}
                    </p>
                </div>
            ) : (
                <>
                    <Abas
                        aba={aba}
                        setAba={setAba}
                        pendentes={dados.projetos.length}
                        concluidos={(dados.concluidos ?? []).length}
                    />
                    {aba === 'pendentes' ? (
                        <>
                            {avisoSorteio && <div className="mb-3 max-w-3xl"><Alert type="info">{avisoSorteio}</Alert></div>}
                            <ListaProjetos
                                titulo="Projetos designados a você"
                                itens={dados.projetos}
                                dados={dados}
                                vazio="Nenhum projeto designado a você por enquanto."
                                onAbrir={setAvaliando}
                            />
                            {dados.pode_avaliar && (
                                <div className="max-w-3xl mt-3 flex items-center gap-3 flex-wrap">
                                    <Button type="button" variant="outline" loading={sorteando} onClick={sortear}>
                                        <span className="material-symbols-outlined text-[18px] align-[-0.2em] mr-1">casino</span>
                                        Sortear outros projetos
                                    </Button>
                                    <p className="text-xs text-on-surface-variant flex-1 min-w-[16rem]">
                                        Troca os projetos que você ainda não abriu por outros. O que já está em
                                        avaliação e o que a organização designou permanecem na lista.
                                    </p>
                                </div>
                            )}
                        </>
                    ) : (
                        <ListaProjetos
                            titulo="Projetos que você já avaliou"
                            itens={dados.concluidos ?? []}
                            dados={dados}
                            vazio="Você ainda não concluiu nenhuma avaliação."
                            onAbrir={setAvaliando}
                        />
                    )}
                </>
            )}

            {avaliando && (
                <AvaliacaoModal
                    avaliacaoId={avaliando}
                    teste={modoTeste && dados?.is_demo}
                    somenteLeitura={!dados?.pode_avaliar}
                    onFechar={() => setAvaliando(null)}
                    onAtualizado={() => carregar(modoTeste)}
                />
            )}
            {confirmDialog}
        </AppShell>
    );
}
