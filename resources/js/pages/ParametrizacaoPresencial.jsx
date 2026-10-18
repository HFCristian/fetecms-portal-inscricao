import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getParametrizacaoPresencial, salvarParametrizacaoPresencial } from '../lib/presencial.js';

/**
 * Parametrização → **Avaliação presencial** (Sprint 234). Por ora, uma
 * subseção: o **comitê especial**, com a regra de avaliar apenas os projetos
 * designados. Os números da avaliação no estande continuam em Avaliação
 * presencial → Distribuição presencial, e os horários em Datas e períodos.
 */
export default function ParametrizacaoPresencial() {
    const [dados, setDados] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');

    useEffect(() => {
        getParametrizacaoPresencial()
            .then(setDados)
            .catch(() => setAlerta('Não foi possível carregar a parametrização da avaliação presencial.'));
    }, []);

    async function alternar(valor) {
        setSalvando(true);
        setAlerta('');
        setSucesso('');
        try {
            const r = await salvarParametrizacaoPresencial(valor);
            setDados(r.data);
            setSucesso(r.meta?.message ?? 'Salvo.');
        } catch (e) {
            setAlerta(extractErrors(e).message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <AppShell>
            <Link to="/admin/parametrizacao" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Parametrização
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação presencial</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Regras da avaliação no estande, por edição. Os horários dos turnos ficam em{' '}
                <Link to="/admin/parametrizacao/datas" className="underline">Datas e períodos</Link>; as vagas e o peso da
                comissão, em Avaliação presencial → Distribuição presencial.
            </p>

            <div className="max-w-3xl space-y-3 mb-4">
                <Alert>{alerta}</Alert>
                <Alert type="info">{sucesso}</Alert>
            </div>

            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 max-w-3xl" aria-label="Comitê especial">
                <h2 className="font-display text-lg font-semibold text-on-surface mb-3 flex items-center gap-2">
                    <span className="material-symbols-outlined text-[22px] text-primary-container">star</span>
                    Comitê especial
                </h2>
                {dados === null ? (
                    !alerta && <p className="text-sm text-on-surface-variant">Carregando…</p>
                ) : (
                    <>
                        <Toggle
                            checked={dados.comite_so_designados}
                            onChange={(v) => { if (!salvando) alternar(v); }}
                            label="Avaliar apenas projetos designados"
                            description="Ligada, quem é da comissão especial só inicia no estande o projeto designado a ele — em Comitê especial → Designações ou na distribuição presencial. Para avaliar outro, o avaliador pede permissão, e a organização decide em Comitê especial → Designações."
                        />
                        {dados.comite_so_designados && (
                            <p className="text-sm text-on-surface-variant mt-4">
                                {dados.pedidos_pendentes > 0
                                    ? <><strong className="text-on-surface">{dados.pedidos_pendentes}</strong> pedido(s) de permissão aguardando. </>
                                    : 'Nenhum pedido de permissão aguardando. '}
                                <Link to="/admin/comite/designacoes" className="underline">Ver os pedidos</Link>
                            </p>
                        )}
                    </>
                )}
            </section>
        </AppShell>
    );
}
