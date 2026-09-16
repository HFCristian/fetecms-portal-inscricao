import { useCallback, useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getPresencial, responderPresencial } from '../lib/avaliacaoPresencial.js';

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

    const carregar = useCallback((teste) => getPresencial(teste)
        .then(setDados)
        .catch(() => setAlert('Não foi possível carregar a sua resposta.')), []);

    useEffect(() => { carregar(modoTeste); }, [carregar, modoTeste]);

    async function responder(presencial) {
        setSalvando(true); setAlert(''); setSucesso('');
        try {
            const resp = await responderPresencial(presencial, modoTeste);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? '');
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlert(Object.values(fields ?? {})[0] || message || 'Não foi possível salvar.');
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

                    {recusou && (
                        <p className="text-sm text-on-surface-variant">
                            Sem problema — sua avaliação online continua valendo normalmente.
                        </p>
                    )}
                </div>
            )}
        </AppShell>
    );
}
