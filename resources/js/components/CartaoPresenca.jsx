import { useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { marcarPresenca } from '../lib/contasTemporarias.js';

/**
 * A tela de espera de uma conta temporária: marcar presença no turno e esperar
 * a organização confirmar.
 *
 * Enquanto a presença não é aprovada, a conta não abre aba nenhuma — então este
 * cartão é a única coisa que a pessoa vê ao entrar, e ele precisa dizer
 * exatamente em que pé está: falta marcar, falta aprovar, ou foi recusada (e
 * por quê).
 */
export default function CartaoPresenca({ presenca, onAtualizar }) {
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');

    if (!presenca || presenca.aprovada) return null;

    async function marcar() {
        setSalvando(true);
        setErro('');
        try {
            const resp = await marcarPresenca();
            onAtualizar?.(resp.data);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível registrar a presença.');
        } finally {
            setSalvando(false);
        }
    }

    const titulo = presenca.status === 'rejeitada'
        ? 'A sua presença foi recusada'
        : presenca.status === 'pendente'
            ? 'Presença registrada — aguardando a organização'
            : presenca.agendada
                ? 'O seu acesso ainda não começou'
                : presenca.vencida
                    ? 'O prazo do seu acesso terminou'
                    : 'Marque a sua presença para começar';

    return (
        <div className="max-w-3xl bg-surface-container-lowest rounded-xl fetec-card-shadow p-6">
            <span className="material-symbols-outlined text-primary-container text-3xl">
                {presenca.status === 'rejeitada' ? 'block' : 'how_to_reg'}
            </span>
            <h2 className="font-display text-lg font-semibold text-on-surface mt-2">{titulo}</h2>

            <p className="text-sm text-on-surface-variant mt-1">
                {presenca.status === 'rejeitada' ? (
                    <>Procure a organização do setor <strong>{presenca.setor_label}</strong> para resolver.</>
                ) : presenca.status === 'pendente' ? (
                    <>Avise a organização do setor <strong>{presenca.setor_label}</strong>: assim que a
                    presença for aprovada, a sua aba abre aqui mesmo.</>
                ) : presenca.agendada ? (
                    <>O seu turno começa em <strong>{presenca.valido_de_label}</strong>.</>
                ) : presenca.vencida ? (
                    <>O acesso valeu até <strong>{presenca.expira_em_label}</strong>. Peça a renovação à
                    organização.</>
                ) : (
                    <>Ao chegar para o seu turno, marque presença abaixo. A organização do setor{' '}
                    <strong>{presenca.setor_label}</strong> confirma e o seu acesso abre.</>
                )}
            </p>

            {presenca.status === 'rejeitada' && presenca.motivo && (
                <p className="mt-3 text-sm text-on-surface border border-error/40 bg-error-container/20 rounded-lg p-3">
                    <strong>Motivo:</strong> {presenca.motivo}
                </p>
            )}

            {presenca.turnos?.length > 0 && (
                <ul className="flex flex-wrap gap-1 mt-3">
                    {presenca.turnos.map((t, i) => (
                        <li
                            key={i}
                            className={`text-xs px-2 py-0.5 rounded-full ${
                                t.agora
                                    ? 'bg-secondary-container text-on-secondary-container font-semibold'
                                    : 'bg-surface-variant text-on-surface-variant'
                            }`}
                        >
                            {t.inicio_label} → {t.fim_label}
                        </li>
                    ))}
                </ul>
            )}

            {erro && <div className="mt-3"><Alert>{erro}</Alert></div>}

            {presenca.precisa_marcar && (
                <div className="mt-4">
                    <Button type="button" loading={salvando} onClick={marcar}>
                        <span className="material-symbols-outlined text-[20px]">how_to_reg</span>
                        Marcar presença
                    </Button>
                </div>
            )}
        </div>
    );
}
