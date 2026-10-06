import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import AvaliacaoPresencialModal from '../components/AvaliacaoPresencialModal.jsx';
import SinalizacoesProjeto from '../components/SinalizacoesProjeto.jsx';
import {
    getPainelPresencial, getAvaliacaoPresencial, iniciarAvaliacaoPresencial,
} from '../lib/avaliacaoPresencial.js';

const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

const local = (l) =>
    !l || (!l.estande && !l.turno_label)
        ? 'Estande a definir'
        : [l.estande ? `Estande ${l.estande}` : null, l.turno_label].filter(Boolean).join(' · ');

const cartao = 'bg-surface-container-lowest rounded-xl fetec-card-shadow p-4';

/**
 * Aba **Avaliação presencial** do avaliador (Sprint 171).
 *
 * Só existe para quem aceitou avaliar no dia da feira, e só abre **durante um
 * turno em que a organização o ativou** na cabine da avaliação. Fora disso a
 * aba aparece fechada, dizendo por quê e quando é o próximo turno — o que ele
 * já avaliou continua legível.
 *
 * Aberta, ela mostra o que a distribuição do turno entregou a ele e os
 * estandes que ele ainda pode pegar no corredor (do turno, credenciados e
 * checados). A avaliação em si é o wizard da rubrica presencial.
 */
export default function AvaliadorAvaliacaoPresencial() {
    const [painel, setPainel] = useState(null);
    const [modoTeste, setModoTeste] = useState(false);
    const [aberta, setAberta] = useState(null);
    const [alert, setAlert] = useState('');
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

    const pendentes = (painel?.minhas ?? []).filter((a) => a.status !== 'concluida');
    const avaliados = (painel?.minhas ?? []).filter((a) => a.status === 'concluida');
    const turno = painel?.turno;

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação presencial</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Avalie os estandes dos finalistas durante o seu turno. Cada avaliação vale até o fim do
                turno mais {painel?.margem_minutos ?? 30} minutos.
            </p>

            {painel?.is_demo && (
                <div className="mb-4 max-w-3xl">
                    <Toggle
                        checked={modoTeste}
                        onChange={setModoTeste}
                        label="Modo de teste"
                        description="Conta demo: avalia a lista de demonstração sem esperar o turno nem a ativação na cabine."
                    />
                </div>
            )}

            <div className="max-w-3xl space-y-3 mb-4">
                <Alert>{alert}</Alert>
                {painel && !painel.aberto && painel.motivo_fechado && <Alert type="info">{painel.motivo_fechado}</Alert>}
            </div>

            {painel === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <div className="max-w-3xl space-y-4">
                    {(turno || painel.meus_turnos?.length > 0) && (
                        <section className={cartao}>
                            {turno && (
                                <p className="text-sm text-on-surface">
                                    <span className="material-symbols-outlined text-[18px] align-[-0.2em] text-secondary mr-1">schedule</span>
                                    <strong>{turno.rotulo}</strong> — avaliações aceitas até as {turno.prazo_label}.
                                </p>
                            )}
                            {painel.meus_turnos?.length > 0 && (
                                <p className="text-xs text-on-surface-variant mt-1">
                                    Seus turnos ativados: {painel.meus_turnos.map((o) => o.rotulo).join('; ')}.
                                </p>
                            )}
                        </section>
                    )}

                    <section className={cartao}>
                        <h2 className="font-display text-base font-semibold text-on-surface mb-1">Para avaliar</h2>
                        {pendentes.length === 0 ? (
                            <p className="text-sm text-on-surface-variant">
                                Nada com você agora.{painel.aberto ? ' Escolha um estande livre na lista abaixo.' : ''}
                            </p>
                        ) : (
                            <ul className="divide-y divide-outline-variant/30">
                                {pendentes.map((a) => (
                                    <li key={a.id} className="py-3 flex flex-wrap items-center gap-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-semibold text-on-surface truncate">{a.titulo}</p>
                                            <p className="text-xs text-on-surface-variant">
                                                {local(a.local)}{a.area ? ` · ${a.area}` : ''}
                                                {a.designacao_manual ? ' · designado pela organização' : ''}
                                                {a.prazo_label ? ` · até ${a.prazo_label}` : ''}
                                            </p>
                                            <SinalizacoesProjeto sinalizacoes={a.sinalizacoes} compacto />
                                        </div>
                                        <span className={`text-xs font-semibold px-2 py-1 rounded-full ${
                                            a.expirada ? 'bg-error-container text-on-error-container' : 'bg-surface-variant text-on-surface-variant'
                                        }`}>
                                            {a.status_label}
                                        </span>
                                        <Button type="button" variant="outline" onClick={() => abrir(a.id)} aria-label={`Abrir ${a.titulo}`}>
                                            {!a.pode_escrever ? 'Ver' : a.status === 'em_andamento' ? 'Continuar' : 'Abrir'}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {painel.aberto && (
                        <section className={cartao}>
                            <h2 className="font-display text-base font-semibold text-on-surface mb-1">Estandes disponíveis</h2>
                            <p className="text-xs text-on-surface-variant mb-2">
                                Projetos do seu turno, credenciados e com o estande checado, que ainda não receberam as{' '}
                                {painel.max_por_projeto} avaliações presenciais. Escolha o que estiver livre no corredor.
                            </p>
                            {painel.disponiveis.length === 0 ? (
                                <p className="text-sm text-on-surface-variant">Nenhum estande disponível agora.</p>
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
                                                <SinalizacoesProjeto sinalizacoes={p.sinalizacoes} compacto />
                                            </div>
                                            <Button type="button" variant="outline" onClick={() => escolher(p)} aria-label={`Avaliar ${p.titulo}`}>
                                                Avaliar
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
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
                                            <p className="text-xs text-on-surface-variant">{local(a.local)}</p>
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
                    itens={painel.itens}
                    teste={modoTeste}
                    onAtualizado={carregar}
                    onFechar={() => { setAberta(null); carregar(); }}
                />
            )}
            {dialogo}
        </AppShell>
    );
}
