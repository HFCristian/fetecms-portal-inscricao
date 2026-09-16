import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import AvaliacaoPresencialModal from '../components/AvaliacaoPresencialModal.jsx';
import {
    getPresencial, responderPresencial, getPainelPresencial,
    iniciarAvaliacaoPresencial, salvarRascunhoPresencial, concluirAvaliacaoPresencial,
} from '../lib/avaliacaoPresencial.js';

const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

const local = (l) =>
    !l || (!l.estande && !l.turno_label)
        ? 'Estande a definir'
        : [l.estande ? `Estande ${l.estande}` : null, l.turno_label].filter(Boolean).join(' · ');

/**
 * Aba "Presencial" do avaliador.
 *
 * Ele responde se pretende avaliar **no dia da feira**. Quem aceita passa a ver
 * as orientações da organização (local, horário, o que levar); quem recusa
 * continua vendo a pergunta — e só ela —, porque mudar de ideia é permitido até
 * o evento começar.
 */
export default function AvaliadorPresencial() {
    const [dados, setDados] = useState(null);
    const [modoTeste, setModoTeste] = useState(false);
    const [salvando, setSalvando] = useState(false);
    const [alert, setAlert] = useState('');
    const [sucesso, setSucesso] = useState('');
    // O painel do dia do evento só existe para quem aceitou.
    const [painel, setPainel] = useState(null);
    const [aberta, setAberta] = useState(null);
    const [erroModal, setErroModal] = useState('');

    const carregar = useCallback((teste) => getPresencial(teste)
        .then(setDados)
        .catch(() => setAlert('Não foi possível carregar a sua resposta.')), []);

    const carregarPainel = useCallback((teste) => getPainelPresencial(teste)
        .then(setPainel)
        .catch(() => setPainel(null)), []);

    useEffect(() => { carregar(modoTeste); }, [carregar, modoTeste]);
    useEffect(() => { carregarPainel(modoTeste); }, [carregarPainel, modoTeste]);

    async function responder(presencial) {
        setSalvando(true); setAlert(''); setSucesso('');
        try {
            const resp = await responderPresencial(presencial, modoTeste);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? '');
            await carregarPainel(modoTeste);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlert(Object.values(fields ?? {})[0] || message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    async function abrir(projetoId) {
        setErroModal(''); setAlert('');
        try {
            setAberta(await iniciarAvaliacaoPresencial(projetoId, modoTeste));
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlert(Object.values(fields ?? {})[0] || message || 'Não foi possível abrir a avaliação.');
            await carregarPainel(modoTeste);
        }
    }

    async function gravar(dados, concluir) {
        setSalvando(true); setErroModal('');
        try {
            const fn = concluir ? concluirAvaliacaoPresencial : salvarRascunhoPresencial;
            const resp = await fn(aberta.id, dados, modoTeste);
            setAberta(resp.data);
            setSucesso(resp.meta?.message ?? '');
            await carregarPainel(modoTeste);
            if (concluir) setAberta(null);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErroModal(Object.values(fields ?? {})[0] || message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    const aceitou = dados?.presencial === true;
    const recusou = dados?.presencial === false;

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação presencial</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Além da avaliação online, a feira precisa de avaliadores <strong>no dia do
                evento</strong>, visitando os estandes dos finalistas. Diga aqui se você pretende
                participar.
            </p>

            {dados?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Conta demo: responda mesmo depois de o evento ter começado."
                    />
                </div>
            )}

            <div className="max-w-3xl space-y-3">
                <Alert>{alert}</Alert>
                <Alert type="info">{sucesso}</Alert>
            </div>

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <div className="max-w-3xl space-y-4 mt-3">
                    <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                        <h2 className="font-display text-base font-semibold text-on-surface mb-1">
                            Você quer avaliar presencialmente na feira?
                        </h2>
                        <p className="text-xs text-on-surface-variant mb-3">
                            {dados.evento_de_label
                                ? `O evento acontece de ${dados.evento_de_label} a ${dados.evento_ate_label ?? '—'}.`
                                : 'As datas do evento ainda não foram divulgadas.'}
                            {dados.pode_alterar
                                ? ' Você pode mudar a resposta até o início do evento.'
                                : ' O evento já começou: para alterar, fale com a organização.'}
                        </p>

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant={aceitou ? 'primary' : 'outline'}
                                disabled={salvando || !dados.pode_alterar}
                                onClick={() => responder(true)}
                            >
                                <span className="material-symbols-outlined text-[20px]">
                                    {aceitou ? 'check_circle' : 'radio_button_unchecked'}
                                </span>
                                Sim, quero participar
                            </Button>
                            <Button
                                type="button"
                                variant={recusou ? 'primary' : 'outline'}
                                disabled={salvando || !dados.pode_alterar}
                                onClick={() => responder(false)}
                            >
                                <span className="material-symbols-outlined text-[20px]">
                                    {recusou ? 'check_circle' : 'radio_button_unchecked'}
                                </span>
                                Não vou participar
                            </Button>
                        </div>

                        {!dados.respondido && (
                            <p className="text-xs text-on-surface-variant mt-3">
                                Você ainda não respondeu.
                            </p>
                        )}
                    </section>

                    {/* As orientações são o que a aba entrega em troca do "sim". */}
                    {aceitou && (
                        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                            <h2 className="font-display text-base font-semibold text-on-surface mb-1">
                                Orientações para o dia do evento
                            </h2>
                            {dados.informacoes ? (
                                <p className="text-sm text-on-surface whitespace-pre-line">{dados.informacoes}</p>
                            ) : (
                                <p className="text-sm text-on-surface-variant">
                                    A organização ainda não publicou as orientações. Elas aparecem aqui
                                    assim que forem divulgadas.
                                </p>
                            )}
                        </section>
                    )}

                    {/* O trabalho do dia: o que está com ele e o que pode pegar. */}
                    {aceitou && painel && (
                        <>
                            {!painel.aberto && painel.motivo_fechado && (
                                <Alert type="info">{painel.motivo_fechado}</Alert>
                            )}

                            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                                <h2 className="font-display text-base font-semibold text-on-surface mb-1">
                                    Minhas avaliações do evento
                                </h2>
                                {painel.minhas.length === 0 ? (
                                    <p className="text-sm text-on-surface-variant">
                                        Nenhuma avaliação aberta. Escolha um estande na lista abaixo.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-outline-variant/30">
                                        {painel.minhas.map((a) => (
                                            <li key={a.id} className="py-3 flex flex-wrap items-center gap-3">
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-semibold text-on-surface truncate">{a.titulo}</p>
                                                    <p className="text-xs text-on-surface-variant">
                                                        {local(a.local)}{a.area ? ` · ${a.area}` : ''}
                                                        {a.designacao_manual ? ' · designado pela organização' : ''}
                                                    </p>
                                                </div>
                                                <span className="text-xs font-semibold px-2 py-1 rounded-full bg-surface-variant text-on-surface-variant">
                                                    {a.status_label}
                                                </span>
                                                {a.status === 'concluida' ? (
                                                    <span className="text-sm font-bold text-secondary">{nota(a.nota)}</span>
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        disabled={!painel.aberto}
                                                        onClick={() => abrir(a.projeto_id)}
                                                    >
                                                        {a.status === 'em_andamento' ? 'Continuar' : 'Avaliar'}
                                                    </Button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>

                            {painel.aberto && (
                                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                                    <h2 className="font-display text-base font-semibold text-on-surface mb-1">
                                        Estandes disponíveis
                                    </h2>
                                    <p className="text-xs text-on-surface-variant mb-2">
                                        Projetos que ainda não receberam as {painel.max_por_projeto} avaliações
                                        presenciais. Escolha o que estiver livre no corredor.
                                    </p>
                                    {painel.disponiveis.length === 0 ? (
                                        <p className="text-sm text-on-surface-variant">
                                            Nenhum estande disponível agora.
                                        </p>
                                    ) : (
                                        <ul className="divide-y divide-outline-variant/30">
                                            {painel.disponiveis.map((p) => (
                                                <li key={p.id} className="py-3 flex flex-wrap items-center gap-3">
                                                    <div className="min-w-0 flex-1">
                                                        <p className="text-sm text-on-surface truncate">{p.titulo}</p>
                                                        <p className="text-xs text-on-surface-variant">
                                                            {local(p.local)}{p.area ? ` · ${p.area}` : ''}
                                                            {` · ${p.avaliacoes} de ${painel.max_por_projeto} avaliações`}
                                                        </p>
                                                    </div>
                                                    <Button type="button" variant="outline" onClick={() => abrir(p.id)}>
                                                        Avaliar
                                                    </Button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>
                            )}
                        </>
                    )}

                    {recusou && (
                        <p className="text-sm text-on-surface-variant">
                            Sem problema — sua avaliação online continua valendo normalmente.
                        </p>
                    )}
                </div>
            )}

            {aberta && painel && (
                <AvaliacaoPresencialModal
                    avaliacao={aberta}
                    rubrica={painel.rubrica}
                    somenteLeitura={aberta.status === 'concluida' || !painel.aberto}
                    salvando={salvando}
                    erro={erroModal}
                    onSalvar={(d) => gravar(d, false)}
                    onConcluir={(d) => gravar(d, true)}
                    onFechar={() => setAberta(null)}
                />
            )}
        </AppShell>
    );
}
