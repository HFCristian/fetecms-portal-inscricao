import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import AvaliacaoPresencialModal from '../components/AvaliacaoPresencialModal.jsx';
import SinalizacoesProjeto from '../components/SinalizacoesProjeto.jsx';
import MapaAvaliacaoPresencial from '../components/MapaAvaliacaoPresencial.jsx';
import { filtrarPorBusca } from '../lib/buscaEstande.js';
import {
    getPainelPresencial, getAvaliacaoPresencial, iniciarAvaliacaoPresencial, pedirPermissaoPresencial,
} from '../lib/avaliacaoPresencial.js';

// Três casas, como a rubrica presencial: os pesos foram escolhidos para desempatar.
const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(3).replace('.', ',');

const local = (l) =>
    !l || (!l.estande && !l.turno_label)
        ? 'Estande a definir'
        : [l.estande ? `Estande ${l.estande}` : null, l.turno_label].filter(Boolean).join(' · ');

const cartao = 'bg-surface-container-lowest rounded-xl fetec-card-shadow p-4';

/**
 * O pedido de permissão do comitê (Sprint 234): o projeto, a resposta de uma
 * recusa anterior (se houve) e uma justificativa opcional para a organização.
 */
function DialogoPermissao({ projeto, enviando, erro, onEnviar, onCancelar }) {
    const [justificativa, setJustificativa] = useState('');

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="Pedir permissão para avaliar">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 className="font-display text-xl font-semibold text-on-surface">Pedir permissão para avaliar</h3>
                    <p className="text-sm text-on-surface-variant mt-1">
                        <strong className="text-on-surface">{projeto.titulo}</strong> — {local(projeto.local)}
                    </p>
                </div>
                <p className="text-sm text-on-surface-variant">
                    Este projeto não está entre as suas designações. A organização recebe o pedido em Comitê
                    especial e, aprovando, você pode iniciar a avaliação.
                </p>
                {projeto.permissao === 'recusada' && (
                    <p className="text-sm rounded-lg bg-error-container/40 px-3 py-2 text-on-surface">
                        O pedido anterior foi recusado{projeto.permissao_resposta ? `: “${projeto.permissao_resposta}”` : '.'}
                    </p>
                )}
                <label className="block text-sm text-on-surface">
                    Por que você quer avaliar este projeto? <span className="text-on-surface-variant">(opcional)</span>
                    <textarea
                        className="mt-1 w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm outline-none focus:border-primary-container"
                        rows={3}
                        maxLength={500}
                        value={justificativa}
                        onChange={(e) => setJustificativa(e.target.value)}
                    />
                </label>
                <Alert>{erro}</Alert>
                <div className="flex justify-end gap-3">
                    <Button type="button" variant="outline" onClick={onCancelar}>Cancelar</Button>
                    <Button type="button" loading={enviando} onClick={() => onEnviar(justificativa)}>Enviar pedido</Button>
                </div>
            </div>
        </div>
    );
}

/** Busca por título ou número do estande. */
function CampoBusca({ valor, onMudar, rotulo }) {
    return (
        <input
            type="search"
            className="w-full sm:w-64 bg-surface border border-outline-variant rounded-lg px-3 py-1.5 text-sm text-on-surface outline-none focus:border-primary-container"
            placeholder="Projeto ou estande"
            aria-label={rotulo}
            value={valor}
            onChange={(e) => onMudar(e.target.value)}
        />
    );
}

/** Uma avaliação no estande que está com ele (designada ou escolhida). */
function LinhaMinha({ a, onAbrir }) {
    return (
        <li className="py-3 flex flex-wrap items-center gap-3">
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-on-surface truncate">{a.titulo}</p>
                <p className="text-xs text-on-surface-variant">
                    {local(a.local)}{a.area ? ` · ${a.area}` : ''}
                    {a.designacao_manual ? ' · designado pela organização' : ''}
                    {a.vaga === 'comite' ? ' · vaga da comissão' : ''}
                    {a.prazo_label ? ` · até ${a.prazo_label}` : ''}
                </p>
                <SinalizacoesProjeto sinalizacoes={a.sinalizacoes} compacto />
            </div>
            <span className={`text-xs font-semibold px-2 py-1 rounded-full ${
                a.expirada ? 'bg-error-container text-on-error-container' : 'bg-surface-variant text-on-surface-variant'
            }`}>
                {a.status_label}
            </span>
            <Button type="button" variant="outline" onClick={() => onAbrir(a.id)} aria-label={`Abrir ${a.titulo}`}>
                {!a.pode_escrever ? 'Ver' : a.status === 'em_andamento' ? 'Continuar' : 'Abrir'}
            </Button>
        </li>
    );
}

const SELO_PEDIDO = {
    pendente: 'bg-[#fff4cc] text-[#5c4a00]',
    aprovado: 'bg-[#d4f0dc] text-[#004d15]',
    recusado: 'bg-error-container text-on-error-container',
};

/**
 * Aba **Avaliação presencial** do avaliador (Sprints 171 e 221).
 *
 * Só existe para quem aceitou avaliar no dia da feira, e só abre **durante um
 * turno** do evento — a ativação na cabine saiu na Sprint 221. Fora disso a aba
 * aparece fechada, dizendo por quê e quando é o próximo turno — o que ele já
 * avaliou continua legível, e o mapa fica para consulta.
 *
 * Aberta, ela mostra o que a organização designou a ele (se designou) e o
 * **mapa**: ele escolhe o turno, clica no estande e inicia. Embaixo, a mesma
 * escolha em lista (do turno, credenciados e checados). A avaliação em si é o
 * wizard da rubrica presencial.
 */
export default function AvaliadorAvaliacaoPresencial() {
    const [painel, setPainel] = useState(null);
    const [modoTeste, setModoTeste] = useState(false);
    const [aberta, setAberta] = useState(null);
    const [alert, setAlert] = useState('');
    const [info, setInfo] = useState('');
    const [pedindo, setPedindo] = useState(null);
    const [enviandoPedido, setEnviandoPedido] = useState(false);
    const [erroPedido, setErroPedido] = useState('');
    const [buscaDesignados, setBuscaDesignados] = useState('');
    const [buscaOutros, setBuscaOutros] = useState('');
    const [confirm, dialogo] = useConfirm();

    const carregar = useCallback(() => getPainelPresencial(modoTeste)
        .then(setPainel)
        .catch(() => setAlert('Não foi possível carregar a avaliação presencial.')), [modoTeste]);

    useEffect(() => { carregar(); }, [carregar]);

    async function abrir(avaliacaoId) {
        setAlert('');
        try {
            setAberta(await getAvaliacaoPresencial(avaliacaoId, modoTeste));
        } catch (e) {
            setAlert(extractErrors(e).message || 'Não foi possível abrir a avaliação.');
        }
    }

    async function escolher(projeto) {
        const ok = await confirm({
            title: 'Avaliar este estande?',
            message: `${projeto.titulo} — ${local(projeto.local)}. Ao iniciar, o estande fica com você até o envio.`,
            confirmLabel: 'Iniciar',
        });
        if (!ok) return;

        setAlert('');
        try {
            setAberta(await iniciarAvaliacaoPresencial(projeto.id, modoTeste));
            await carregar();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlert(Object.values(fields ?? {})[0] || message || 'Não foi possível abrir a avaliação.');
            await carregar();
        }
    }

    function abrirPedido(projeto) {
        setErroPedido('');
        setPedindo(projeto);
    }

    async function enviarPedido(justificativa) {
        setEnviandoPedido(true);
        setErroPedido('');
        try {
            const r = await pedirPermissaoPresencial(pedindo.id, justificativa, modoTeste);
            setPedindo(null);
            setAlert('');
            setInfo(r.meta?.message ?? 'Pedido enviado.');
            await carregar();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErroPedido(Object.values(fields ?? {})[0] || message || 'Não foi possível enviar o pedido.');
        } finally {
            setEnviandoPedido(false);
        }
    }

    const pendentes = (painel?.minhas ?? []).filter((a) => a.status !== 'concluida');
    const avaliados = (painel?.minhas ?? []).filter((a) => a.status === 'concluida');
    const turno = painel?.turno;

    // Designados (Sprint 235): o que a distribuição ou a organização entregou
    // no estande e o que o comitê designou e ainda não virou avaliação aqui.
    const meus = pendentes.filter((a) => !a.escolha_livre);
    const doComite = painel?.designados_comite ?? [];
    const totalDesignados = meus.length + doComite.length;
    const designadosMeus = filtrarPorBusca(meus, buscaDesignados);
    const designadosComite = filtrarPorBusca(doComite, buscaDesignados);
    const escolhidos = pendentes.filter((a) => a.escolha_livre);
    // Os disponíveis sem o que já está na seção de designados.
    const idsDoComite = new Set(doComite.map((p) => p.id));
    const outros = (painel?.disponiveis ?? []).filter((p) => !idsDoComite.has(p.id));
    const outrosFiltrados = filtrarPorBusca(outros, buscaOutros);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação presencial</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Avalie os estandes dos finalistas no horário de avaliação do turno: escolha o estande no
                mapa — com ou sem designação — e inicie. Cada avaliação vale até o fim do horário de
                avaliação do turno em que foi aberta.
            </p>

            {painel?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Conta demo: avalia a lista de demonstração sem esperar o turno."
                    />
                </div>
            )}

            <div className="max-w-5xl space-y-3 mb-4">
                <Alert>{alert}</Alert>
                <Alert type="info">{info}</Alert>
                {painel && !painel.aberto && painel.motivo_fechado && <Alert type="info">{painel.motivo_fechado}</Alert>}
            </div>

            {painel === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <div className="max-w-5xl space-y-4">
                    {(turno || painel.comissao) && (
                        <section className={cartao}>
                            {turno && (
                                <p className="text-sm text-on-surface">
                                    <span className="material-symbols-outlined text-[18px] align-[-0.2em] text-secondary mr-1">schedule</span>
                                    <strong>{turno.rotulo}</strong> — avaliações aceitas até as {turno.prazo_label}.
                                </p>
                            )}
                            {painel.comissao && painel.vagas && (
                                <p className="text-xs text-on-surface-variant mt-1">
                                    <span className="material-symbols-outlined text-[16px] align-[-0.2em] text-secondary mr-1">star</span>
                                    Você é da <strong>comissão especial</strong>: cada projeto guarda{' '}
                                    {painel.vagas.comite} vaga(s) para a comissão, e a sua avaliação nela pesa{' '}
                                    {String(painel.vagas.peso_comite).replace('.', ',')} na média. Com a vaga da comissão
                                    tomada, você entra numa vaga comum, com peso 1.
                                </p>
                            )}
                            {painel.so_designados && (
                                <p className="text-xs text-on-surface mt-2 rounded-lg bg-[#fff4cc] px-3 py-2">
                                    <span className="material-symbols-outlined text-[16px] align-[-0.2em] mr-1">lock</span>
                                    A organização ligou a regra do comitê: você avalia <strong>só os projetos designados a
                                    você</strong>. Para avaliar outro, peça permissão — no mapa (estande amarelo) ou na lista.
                                </p>
                            )}
                        </section>
                    )}

                    <section className={cartao} aria-label="Designados a você">
                        <div className="flex flex-wrap items-center justify-between gap-2 mb-1">
                            <h2 className="font-display text-base font-semibold text-on-surface">
                                Designados a você
                                <span className="ml-2 text-sm font-normal text-on-surface-variant tabular-nums">({totalDesignados})</span>
                            </h2>
                            {totalDesignados > 0 && (
                                <CampoBusca valor={buscaDesignados} onMudar={setBuscaDesignados} rotulo="Buscar entre os designados" />
                            )}
                        </div>
                        <p className="text-xs text-on-surface-variant mb-2">
                            O que a organização (ou o comitê) entregou a você, ainda sem envio.
                        </p>
                        {totalDesignados === 0 ? (
                            <p className="text-sm text-on-surface-variant">
                                Nenhum projeto designado a você.{painel.aberto && !painel.so_designados ? ' Escolha um estande no mapa abaixo.' : ''}
                            </p>
                        ) : designadosMeus.length + designadosComite.length === 0 ? (
                            <p className="text-sm text-on-surface-variant">Nenhum designado encontrado.</p>
                        ) : (
                            <ul className="divide-y divide-outline-variant/30">
                                {designadosMeus.map((a) => <LinhaMinha key={a.id} a={a} onAbrir={abrir} />)}
                                {designadosComite.map((p) => (
                                    <li key={`comite-${p.id}`} className="py-3 flex flex-wrap items-center gap-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-semibold text-on-surface truncate">{p.titulo}</p>
                                            <p className="text-xs text-on-surface-variant">
                                                {local(p.local)}{p.area ? ` · ${p.area}` : ''} · designado pelo comitê
                                            </p>
                                            <SinalizacoesProjeto sinalizacoes={p.sinalizacoes} compacto />
                                        </div>
                                        <span className="text-xs font-semibold px-2 py-1 rounded-full bg-[#e9ddff] text-[#2a0058]">A iniciar</span>
                                        <Button type="button" variant="outline" onClick={() => escolher(p)} aria-label={`Avaliar ${p.titulo}`}>
                                            Avaliar
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {escolhidos.length > 0 && (
                        <section className={cartao} aria-label="Escolhidos por você">
                            <h2 className="font-display text-base font-semibold text-on-surface mb-1">Escolhidos por você</h2>
                            <p className="text-xs text-on-surface-variant mb-2">Os estandes que você abriu pelo mapa ou pela lista.</p>
                            <ul className="divide-y divide-outline-variant/30">
                                {escolhidos.map((a) => <LinhaMinha key={a.id} a={a} onAbrir={abrir} />)}
                            </ul>
                        </section>
                    )}

                    {painel.mapa && (
                        <section className={cartao}>
                            <h2 className="font-display text-base font-semibold text-on-surface mb-1">Mapa do evento</h2>
                            <p className="text-xs text-on-surface-variant mb-3">
                                Escolha o turno e clique no estande: o verde claro está livre para você iniciar; o roxo
                                já está com você.
                            </p>
                            <MapaAvaliacaoPresencial
                                mapa={painel.mapa}
                                onIniciar={escolher}
                                onAbrir={abrir}
                                onPedirPermissao={abrirPedido}
                            />
                        </section>
                    )}

                    {painel.aberto && (
                        <section className={cartao} aria-label="Estandes disponíveis">
                            <div className="flex flex-wrap items-center justify-between gap-2 mb-1">
                                <h2 className="font-display text-base font-semibold text-on-surface">Estandes disponíveis</h2>
                                {outros.length > 0 && (
                                    <CampoBusca valor={buscaOutros} onMudar={setBuscaOutros} rotulo="Buscar entre os estandes disponíveis" />
                                )}
                            </div>
                            <p className="text-xs text-on-surface-variant mb-2">
                                Projetos do seu turno, credenciados e com o estande checado, que ainda têm vaga para você
                                ({painel.max_por_projeto} avaliações presenciais por projeto). Escolha o que estiver livre no
                                corredor.
                            </p>
                            {outros.length === 0 ? (
                                <p className="text-sm text-on-surface-variant">Nenhum estande disponível agora.</p>
                            ) : outrosFiltrados.length === 0 ? (
                                <p className="text-sm text-on-surface-variant">Nenhum estande encontrado.</p>
                            ) : (
                                <ul className="divide-y divide-outline-variant/30">
                                    {outrosFiltrados.map((p) => (
                                        <li key={p.id} className="py-3 flex flex-wrap items-center gap-3">
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm text-on-surface truncate">{p.titulo}</p>
                                                <p className="text-xs text-on-surface-variant">
                                                    {local(p.local)}{p.area ? ` · ${p.area}` : ''}
                                                    {` · ${p.avaliacoes} de ${painel.max_por_projeto} avaliações`}
                                                </p>
                                                <SinalizacoesProjeto sinalizacoes={p.sinalizacoes} compacto />
                                            </div>
                                            {!p.permissao ? (
                                                <Button type="button" variant="outline" onClick={() => escolher(p)} aria-label={`Avaliar ${p.titulo}`}>
                                                    Avaliar
                                                </Button>
                                            ) : p.permissao === 'pendente' ? (
                                                <span className="text-xs font-semibold rounded-full px-2.5 py-1 bg-[#fff4cc] text-[#5c4a00]">
                                                    Permissão pedida
                                                </span>
                                            ) : (
                                                <Button type="button" variant="outline" onClick={() => abrirPedido(p)} aria-label={`Pedir permissão para ${p.titulo}`}>
                                                    Pedir permissão
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}

                    {(painel.pedidos ?? []).length > 0 && (
                        <section className={cartao}>
                            <h2 className="font-display text-base font-semibold text-on-surface mb-1">Seus pedidos de permissão</h2>
                            <ul className="divide-y divide-outline-variant/30">
                                {painel.pedidos.map((p) => (
                                    <li key={p.id} className="py-2 flex flex-wrap items-center gap-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm text-on-surface truncate">{p.titulo}</p>
                                            {p.resposta && <p className="text-xs text-on-surface-variant">Resposta: {p.resposta}</p>}
                                        </div>
                                        <span className={`text-xs font-semibold px-2 py-1 rounded-full ${SELO_PEDIDO[p.status] ?? ''}`}>{p.status_label}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {avaliados.length > 0 && (
                        <section className={cartao}>
                            <h2 className="font-display text-base font-semibold text-on-surface mb-1">Avaliados</h2>
                            <ul className="divide-y divide-outline-variant/30">
                                {avaliados.map((a) => (
                                    <li key={a.id} className="py-3 flex flex-wrap items-center gap-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm text-on-surface truncate">{a.titulo}</p>
                                            <p className="text-xs text-on-surface-variant">
                                                {local(a.local)}{a.vaga === 'comite' ? ' · vaga da comissão' : ''}
                                            </p>
                                        </div>
                                        <span className="text-sm font-bold text-secondary">{nota(a.nota)}</span>
                                        <Button type="button" variant="outline" onClick={() => abrir(a.id)} aria-label={`Ver ${a.titulo}`}>
                                            Ver
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            )}

            {aberta && painel && (
                <AvaliacaoPresencialModal
                    key={aberta.id}
                    avaliacao={aberta}
                    rubrica={painel.rubrica}
                    teste={modoTeste}
                    onAtualizado={carregar}
                    onFechar={() => { setAberta(null); carregar(); }}
                />
            )}
            {pedindo && (
                <DialogoPermissao
                    projeto={pedindo}
                    enviando={enviandoPedido}
                    erro={erroPedido}
                    onEnviar={enviarPedido}
                    onCancelar={() => setPedindo(null)}
                />
            )}
            {dialogo}
        </AppShell>
    );
}
