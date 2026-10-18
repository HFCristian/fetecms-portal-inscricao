import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input, Toggle } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getParametrizacaoPresencial, salvarLancamentoNoturno, salvarParametrizacaoPresencial } from '../lib/presencial.js';

/**
 * O **lançamento noturno** do comitê (Sprint 238): um horário que vale em cada
 * dia do evento, em que quem é da comissão envia as avaliações que anotou no
 * caderno durante o dia. Fim antes do início passa da meia-noite.
 */
function LancamentoNoturno({ noite, onSalvo, onErro }) {
    const [inicio, setInicio] = useState(noite?.inicio ?? '');
    const [fim, setFim] = useState(noite?.fim ?? '');
    const [erros, setErros] = useState({});
    const [salvando, setSalvando] = useState(false);

    async function salvar(i, f) {
        setSalvando(true);
        setErros({});
        try {
            const r = await salvarLancamentoNoturno(i, f);
            setInicio(r.data.comite_noite?.inicio ?? '');
            setFim(r.data.comite_noite?.fim ?? '');
            onSalvo(r);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErros(fields ?? {});
            onErro(Object.values(fields ?? {})[0] || message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <div className="mt-6 pt-5 border-t border-outline-variant/40">
            <h3 className="text-sm font-semibold text-on-surface">Lançamento noturno</h3>
            <p className="text-sm text-on-surface-variant mt-1 mb-3">
                Quem é da comissão especial abre e salva avaliações como rascunho a qualquer hora do evento, e
                envia no horário de avaliação de um turno ou neste horário, que vale em cada dia do evento. Fim
                antes do início passa da meia-noite (22:00–01:00). Os dois em branco desligam.
            </p>
            {noite?.sem_evento && (
                <p className="text-xs text-on-surface-variant mb-3">
                    O período do evento ainda não foi definido em Datas e períodos: sem ele, o horário não vale.
                </p>
            )}
            <div className="flex flex-wrap items-end gap-3">
                <label className="text-xs text-on-surface-variant">
                    Início
                    <Input type="time" aria-label="Início do lançamento noturno" value={inicio}
                        error={erros.inicio} onChange={(e) => setInicio(e.target.value)} />
                </label>
                <label className="text-xs text-on-surface-variant">
                    Fim
                    <Input type="time" aria-label="Fim do lançamento noturno" value={fim}
                        error={erros.fim} onChange={(e) => setFim(e.target.value)} />
                </label>
                <Button type="button" loading={salvando} onClick={() => salvar(inicio, fim)}>Salvar horário</Button>
                {(noite?.inicio || noite?.fim) && (
                    <Button type="button" variant="outline" disabled={salvando} onClick={() => salvar('', '')}>Desligar</Button>
                )}
            </div>
            <p className="text-xs text-on-surface-variant mt-2">
                {noite?.rotulo ? <>Em vigor: <strong className="text-on-surface">{noite.rotulo}</strong>.</> : 'Desligado: o comitê envia só no horário de avaliação dos turnos.'}
            </p>
        </div>
    );
}

/**
 * Parametrização → **Avaliação presencial** (Sprint 234). Por ora, uma
 * subseção: o **comitê especial**, com a regra de avaliar apenas os projetos
 * designados e o lançamento noturno (Sprint 238). Os números da avaliação no
 * estande continuam em Avaliação presencial → Distribuição presencial, e os
 * horários dos turnos em Datas e períodos.
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
                        <LancamentoNoturno
                            noite={dados.comite_noite}
                            onSalvo={(r) => { setAlerta(''); setDados(r.data); setSucesso(r.meta?.message ?? 'Salvo.'); }}
                            onErro={(m) => { setSucesso(''); setAlerta(m); }}
                        />
                    </>
                )}
            </section>
        </AppShell>
    );
}
