import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert, Button } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getCodigosLista, congelarCodigosLista, enviarCodigosLista } from '../lib/admin.js';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : null);

/**
 * Lista final → **Código do projeto** (Sprint 159).
 *
 * Com a lista certa, cada finalista recebe por e-mail o código do seu projeto
 * (FET.AGR-001) — é o que ele informa no credenciamento e na checagem do
 * estande. Antes de sair, o código é **congelado**: até aqui ele era refeito a
 * cada leitura da lista, e incluir um projeto empurrava o número dos outros.
 * Daí em diante cada projeto guarda o seu, e quem entrar depois ganha o
 * próximo número livre do grupo.
 *
 * O envio é uma mala direta para a equipe inteira (estudantes, orientador e
 * coorientador), com o texto do modelo "Código do projeto" — editável em
 * Comunicação → Modelos de e-mail — e o relatório de sempre.
 */
export default function EnvioCodigosFinalistas({ listaId }) {
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [confirmando, setConfirmando] = useState(false);
    const [ocupado, setOcupado] = useState(false);

    useEffect(() => {
        getCodigosLista(listaId).then(setDados).catch(() => setErro('Não foi possível carregar os códigos.'));
    }, [listaId]);

    async function congelar() {
        setOcupado(true); setErro('');
        try {
            const resp = await congelarCodigosLista(listaId);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? 'Códigos fixados.');
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível fixar os códigos.');
        } finally {
            setOcupado(false);
        }
    }

    async function enviar() {
        setOcupado(true); setErro('');
        try {
            const resp = await enviarCodigosLista(listaId);
            setDados(resp.data);
            setSucesso(resp.meta?.message ?? 'Envio iniciado.');
            setConfirmando(false);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível enviar.');
        } finally {
            setOcupado(false);
        }
    }

    if (dados === null && !erro) return null;

    const lista = dados?.lista;
    const destinatarios = dados?.destinatarios;

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mt-6 max-w-3xl" aria-label="Código do projeto">
            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Código do projeto</h2>
            <p className="text-sm text-on-surface-variant mb-3">
                Envie a cada finalista o código do seu projeto, para o credenciamento e a checagem dos
                estandes. Os códigos são <strong>fixados</strong> antes do envio: depois disso não mudam,
                e quem entrar na lista ganha o próximo número livre do grupo.
            </p>

            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
            {sucesso && <div className="mb-3"><Alert type="info">{sucesso}</Alert></div>}

            {lista && (
                <>
                    <ul className="text-sm text-on-surface-variant mb-3 space-y-0.5">
                        <li>
                            {lista.codigos_congelados_em
                                ? <>Códigos fixados em <strong>{dataHora(lista.codigos_congelados_em)}</strong>.</>
                                : 'Códigos ainda não fixados — a numeração acompanha a composição da lista.'}
                        </li>
                        {lista.codigos_enviados_em && (
                            <li>
                                Último envio em <strong>{dataHora(lista.codigos_enviados_em)}</strong>
                                {lista.codigos_mala_id && (
                                    <> · <Link className="text-primary underline" to={`/admin/mala-direta/${lista.codigos_mala_id}`}>acompanhar o relatório</Link></>
                                )}
                            </li>
                        )}
                        {destinatarios && (
                            <li>
                                {destinatarios.pessoas} {destinatarios.pessoas === 1 ? 'pessoa recebe' : 'pessoas recebem'}
                                {destinatarios.sem_email > 0 && ` · ${destinatarios.sem_email} sem e-mail cadastrado (ficam de fora)`}
                            </li>
                        )}
                    </ul>

                    {!dados.pode_enviar && dados.motivo && (
                        <div className="mb-3"><Alert>{dados.motivo}</Alert></div>
                    )}

                    <div className="flex flex-wrap gap-2">
                        {!lista.codigos_congelados_em && (
                            <Button type="button" variant="outline" loading={ocupado && !confirmando} disabled={!dados.pode_enviar} onClick={congelar}>
                                <span className="material-symbols-outlined text-[20px]">lock</span>
                                Só fixar os códigos
                            </Button>
                        )}
                        <Button type="button" disabled={!dados.pode_enviar} onClick={() => setConfirmando(true)}>
                            <span className="material-symbols-outlined text-[20px]">forward_to_inbox</span>
                            {lista.codigos_enviados_em ? 'Enviar de novo' : 'Enviar código aos finalistas'}
                        </Button>
                    </div>
                </>
            )}

            {confirmando && dados && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                    <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-lg p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                        <div>
                            <h3 className="font-display text-lg font-semibold text-on-surface">Enviar o código aos finalistas?</h3>
                            <p className="text-sm text-on-surface-variant">
                                {destinatarios?.pessoas ?? 0} pessoa(s). Os códigos ficam fixados a partir de agora.
                                O texto é o do modelo “Código do projeto” —{' '}
                                <Link className="text-primary underline" to="/admin/comunicacao/modelos">editar em Modelos de e-mail</Link>.
                            </p>
                        </div>
                        <div className="rounded-lg border border-outline-variant/40 p-3 text-sm">
                            <p className="font-semibold mb-1">{dados.assunto}</p>
                            {dados.formato === 'html'
                                ? <div className="prose prose-sm" dangerouslySetInnerHTML={{ __html: dados.corpo }} />
                                : <p className="whitespace-pre-line text-on-surface-variant">{dados.corpo}</p>}
                        </div>
                        <p className="text-xs text-on-surface-variant">
                            <code>{'{{projetos}}'}</code> vira o código e o título do projeto de quem recebe.
                        </p>
                        <div className="flex justify-end gap-3">
                            <Button type="button" variant="outline" onClick={() => setConfirmando(false)} disabled={ocupado}>Cancelar</Button>
                            <Button type="button" loading={ocupado} onClick={enviar}>Enviar agora</Button>
                        </div>
                    </div>
                </div>
            )}
        </section>
    );
}
