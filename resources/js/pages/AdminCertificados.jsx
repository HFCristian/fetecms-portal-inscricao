import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { getListasCertificados } from '../lib/certificados.js';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—');

function Situacao({ lista }) {
    if (lista.status !== 'gerada') {
        return <span className="rounded-full bg-surface-variant px-2 py-0.5 text-xs font-semibold text-on-surface-variant">Rascunho</span>;
    }
    if (lista.desatualizada) {
        return <span className="rounded-full bg-primary-fixed px-2 py-0.5 text-xs font-semibold text-primary" title="Alterada depois de gerada">Alterada</span>;
    }
    return <span className="rounded-full bg-secondary-container px-2 py-0.5 text-xs font-semibold text-on-secondary-container">Gerada</span>;
}

/**
 * Aba **Certificados** (Sprints 259–260).
 *
 * O portal monta a lista e a organização emite o certificado no SICERT. Em
 * cima, a porta do assistente que gera a lista; embaixo, as listas já feitas —
 * as geradas e os rascunhos —, com quem gerou, o público e quantas pessoas, e
 * o botão para editar quando algo saiu errado.
 */
export default function AdminCertificados() {
    const navigate = useNavigate();
    const [listas, setListas] = useState(null);
    const [erro, setErro] = useState('');

    useEffect(() => {
        getListasCertificados().then(setListas).catch(() => setErro('Não foi possível carregar as listas.'));
    }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-4">Certificados</h1>

            <section aria-label="Gerar certificados" className="rounded-2xl bg-primary-container text-on-primary fetec-card-shadow p-6 md:p-8 mb-8">
                <div className="flex flex-wrap items-center justify-between gap-6">
                    <div className="max-w-2xl">
                        <span className="material-symbols-outlined text-[40px] mb-2">workspace_premium</span>
                        <h2 className="font-display text-2xl font-semibold mb-2">Gerar certificados</h2>
                        <p className="text-sm text-on-primary/85">
                            Escolha a fase (online, presencial ou as duas), o público e a carga horária de cada um;
                            confira os nomes e gere a lista no modelo de importação do SICERT. O rascunho fica salvo a
                            cada passo.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => navigate('/admin/certificados/nova')}
                        className="inline-flex items-center gap-2 rounded-xl bg-surface-container-lowest px-6 py-3 font-display text-lg font-semibold text-primary hover:bg-primary-fixed transition-colors"
                    >
                        <span className="material-symbols-outlined">add_circle</span>
                        Gerar certificados
                    </button>
                </div>
            </section>

            <section aria-label="Listas geradas" className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Listas geradas</h2>
                <p className="text-sm text-on-surface-variant mb-4">
                    As listas desta edição, da mais recente para a mais antiga. Editar volta ao assistente; o arquivo só
                    muda quando a lista é gerada de novo.
                </p>

                {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs uppercase text-on-surface-variant">
                            <tr>
                                <th className="px-2 py-2">Lista</th>
                                <th className="px-2 py-2">Data</th>
                                <th className="px-2 py-2">Público-alvo</th>
                                <th className="px-2 py-2 text-center">Pessoas</th>
                                <th className="px-2 py-2">Gerada por</th>
                                <th className="px-2 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {(listas ?? []).map((l) => (
                                <tr key={l.id} className="border-t border-outline-variant/30 align-top">
                                    <td className="px-2 py-2">
                                        <p className="font-semibold text-on-surface">{l.nome}</p>
                                        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-on-surface-variant">
                                            <Situacao lista={l} />
                                            {l.fases.length > 0 && <span>Fase {l.fases.join(' e ')}</span>}
                                        </div>
                                    </td>
                                    <td className="px-2 py-2 text-xs whitespace-nowrap">
                                        {l.status === 'gerada' ? dataHora(l.gerada_em) : <>Atualizado {dataHora(l.atualizada_em)}</>}
                                    </td>
                                    <td className="px-2 py-2 text-xs">{l.publicos.length > 0 ? l.publicos.join(', ') : '—'}</td>
                                    <td className="px-2 py-2 text-center">
                                        {l.status === 'gerada' ? (
                                            <>
                                                <p className="font-bold text-secondary">{l.total_pessoas}</p>
                                                <p className="text-[11px] text-on-surface-variant">{l.total_certificados} certificado(s)</p>
                                            </>
                                        ) : '—'}
                                    </td>
                                    <td className="px-2 py-2 text-xs">{l.gerada_por ?? (l.criada_por ? `Criada por ${l.criada_por}` : '—')}</td>
                                    <td className="px-2 py-2">
                                        <div className="flex justify-end gap-2">
                                            {l.status === 'gerada' && (
                                                <Button type="button" variant="outline" onClick={() => navigate(`/admin/certificados/listas/${l.id}`)}>
                                                    <span className="material-symbols-outlined text-[18px]">open_in_full</span>
                                                    Abrir
                                                </Button>
                                            )}
                                            <Button type="button" variant="outline" aria-label={`Editar ${l.nome}`}
                                                onClick={() => navigate(`/admin/certificados/listas/${l.id}/assistente`)}>
                                                <span className="material-symbols-outlined text-[18px]">edit</span>
                                                {l.status === 'gerada' ? 'Editar' : 'Continuar'}
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {listas?.length === 0 && (
                                <tr><td colSpan={6} className="px-2 py-6 text-center text-on-surface-variant">Nenhuma lista ainda. Comece por "Gerar certificados".</td></tr>
                            )}
                            {listas === null && !erro && (
                                <tr><td colSpan={6} className="px-2 py-6 text-center text-on-surface-variant">Carregando…</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>
        </AppShell>
    );
}
