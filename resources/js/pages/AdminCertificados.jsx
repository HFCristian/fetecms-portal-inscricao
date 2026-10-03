import { useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input, Select } from '../components/ui.jsx';
import {
    getOpcoesCertificados, getAvaliadoresCertificado, getProjetosDoAvaliador,
    baixarAvaliadoresCertificado, baixarParticipantesCertificado, baixarAvaliacoesNominais, baixarDeclaracaoAvaliador,
} from '../lib/certificados.js';

const GRUPOS_PADRAO = ['estudantes', 'orientadores', 'coorientadores'];

function Baixar({ rotulo = 'Baixar', ocupado, onBaixar }) {
    return (
        <div className="flex gap-2">
            {['xlsx', 'csv'].map((f) => (
                <Button key={f} type="button" variant="outline" loading={ocupado === f} onClick={() => onBaixar(f)}>
                    <span className="material-symbols-outlined text-[18px]">download</span>
                    {rotulo} {f === 'xlsx' ? 'Excel' : 'CSV'}
                </Button>
            ))}
        </div>
    );
}

/** Os projetos que um avaliador avaliou, por fase, com o PDF da declaração. */
function ProjetosDoAvaliador({ avaliador, onFechar }) {
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');

    useEffect(() => {
        getProjetosDoAvaliador(avaliador.id).then(setDados).catch(() => setErro('Não foi possível carregar.'));
    }, [avaliador.id]);

    return (
        <div className="mt-2 rounded-lg border border-outline-variant/50 p-3 text-sm space-y-3">
            {erro && <Alert>{erro}</Alert>}
            {dados && ['online', 'presencial'].map((fase) => (
                <div key={fase}>
                    <p className="font-semibold text-on-surface">
                        Fase {fase === 'online' ? 'online' : 'presencial'} — {dados[fase].length} projeto(s)
                    </p>
                    {dados[fase].length === 0 ? (
                        <p className="text-xs text-on-surface-variant">Nenhuma avaliação concluída.</p>
                    ) : (
                        <ol className="list-decimal pl-5 text-xs text-on-surface">
                            {dados[fase].map((p) => (
                                <li key={`${fase}-${p.projeto_id}`}>{p.titulo} <span className="text-on-surface-variant">· {p.area ?? '—'} · {p.concluida_em}</span></li>
                            ))}
                        </ol>
                    )}
                </div>
            ))}
            <div className="flex gap-2 justify-end">
                <Button type="button" variant="outline" onClick={onFechar}>Fechar</Button>
                <Button type="button" onClick={() => baixarDeclaracaoAvaliador(avaliador.id)}>
                    <span className="material-symbols-outlined text-[18px]">picture_as_pdf</span>
                    Relatório nominal (PDF)
                </Button>
            </div>
        </div>
    );
}

/**
 * Aba **Certificados** (Sprint 163).
 *
 * O portal não emite o certificado: entrega a planilha que a organização usa
 * para emiti-lo. O certificado da **fase online** sai antes da presencial — há
 * avaliador pedindo para processo seletivo com prazo —, então as duas fases são
 * contadas separadas, e a carga horária é calculada pela organização a partir da
 * quantidade. Para quem pede declaração com os títulos, cada avaliador tem o
 * relatório nominal em PDF, e há uma planilha com todas as avaliações.
 */
export default function AdminCertificados() {
    const [opcoes, setOpcoes] = useState(null);
    const [fase, setFase] = useState('online');
    const [busca, setBusca] = useState('');
    const [avaliadores, setAvaliadores] = useState(null);
    const [aberto, setAberto] = useState(null);
    const [grupos, setGrupos] = useState(GRUPOS_PADRAO);
    const [escopo, setEscopo] = useState('finalistas');
    const [faseNominal, setFaseNominal] = useState('online');
    const [ocupado, setOcupado] = useState(null);
    const [erro, setErro] = useState('');

    useEffect(() => {
        getOpcoesCertificados().then(setOpcoes).catch(() => setErro('Não foi possível carregar a aba.'));
    }, []);

    useEffect(() => {
        const t = setTimeout(() => {
            getAvaliadoresCertificado(fase, busca.trim()).then(setAvaliadores).catch(() => setAvaliadores([]));
        }, 250);
        return () => clearTimeout(t);
    }, [fase, busca]);

    async function baixar(chave, fn) {
        setOcupado(chave); setErro('');
        try {
            await fn();
        } catch {
            setErro('Não foi possível gerar o arquivo.');
        } finally {
            setOcupado(null);
        }
    }

    const alternarGrupo = (g) => setGrupos((atuais) => (atuais.includes(g) ? atuais.filter((x) => x !== g) : [...atuais, g]));

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Certificados</h1>
            <p className="text-sm text-on-surface-variant mb-6 max-w-3xl">
                As planilhas para a emissão dos certificados — o portal entrega os dados, a organização emite o
                documento. As fases online e presencial são contadas separadas.
            </p>

            {erro && <div className="mb-4 max-w-4xl"><Alert>{erro}</Alert></div>}

            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-6 max-w-4xl" aria-label="Avaliadores">
                <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Avaliadores</h2>
                <p className="text-sm text-on-surface-variant mb-3">
                    Nome completo, CPF, e-mail, área de avaliação e quantos projetos cada um avaliou em cada fase. A
                    carga horária sai da quantidade.
                </p>
                <div className="flex flex-wrap items-end gap-3 mb-3">
                    <label className="text-sm">
                        <span className="block font-semibold">Quem entra</span>
                        <Select aria-label="Fase dos avaliadores" value={fase} onChange={(e) => setFase(e.target.value)}>
                            {(opcoes?.fases ?? []).map((f) => <option key={f.valor} value={f.valor}>Avaliou na {f.rotulo.toLowerCase()}</option>)}
                        </Select>
                    </label>
                    <Baixar ocupado={ocupado?.startsWith('av-') ? ocupado.slice(3) : null} onBaixar={(f) => baixar(`av-${f}`, () => baixarAvaliadoresCertificado(fase, f))} />
                </div>
                <Input aria-label="Buscar avaliador" placeholder="Buscar por nome ou e-mail…" value={busca} onChange={(e) => setBusca(e.target.value)} />
                <div className="overflow-x-auto mt-3">
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs uppercase text-on-surface-variant">
                            <tr>
                                <th className="px-2 py-1">Avaliador</th>
                                <th className="px-2 py-1">Área</th>
                                <th className="px-2 py-1 text-center">Online</th>
                                <th className="px-2 py-1 text-center">Presencial</th>
                                <th className="px-2 py-1" />
                            </tr>
                        </thead>
                        <tbody>
                            {(avaliadores ?? []).map((a) => (
                                <tr key={a.id} className="border-t border-outline-variant/30 align-top">
                                    <td className="px-2 py-2">
                                        <p className="font-semibold">{a.nome}</p>
                                        <p className="text-xs text-on-surface-variant">{a.cpf ?? 'CPF não informado'} · {a.email}</p>
                                        {aberto === a.id && <ProjetosDoAvaliador avaliador={a} onFechar={() => setAberto(null)} />}
                                    </td>
                                    <td className="px-2 py-2 text-xs">{a.area ?? '—'}</td>
                                    <td className="px-2 py-2 text-center font-bold text-secondary">{a.online}</td>
                                    <td className="px-2 py-2 text-center font-bold">{a.presencial}</td>
                                    <td className="px-2 py-2 text-right">
                                        {aberto !== a.id && (
                                            <Button type="button" variant="outline" onClick={() => setAberto(a.id)}>Projetos avaliados</Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {avaliadores?.length === 0 && (
                                <tr><td colSpan={5} className="px-2 py-4 text-center text-on-surface-variant">Nenhum avaliador neste recorte.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mb-6 max-w-4xl" aria-label="Participantes">
                <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Todos os participantes</h2>
                <p className="text-sm text-on-surface-variant mb-3">
                    Nome completo, CPF, e-mail, função e projeto ou atividade de cada grupo marcado.
                </p>
                <div className="grid sm:grid-cols-2 gap-1 text-sm mb-3">
                    {(opcoes?.grupos ?? []).map((g) => (
                        <label key={g.valor} className="inline-flex items-center gap-2">
                            <input type="checkbox" checked={grupos.includes(g.valor)} onChange={() => alternarGrupo(g.valor)} />
                            {g.rotulo}
                        </label>
                    ))}
                </div>
                <div className="flex flex-wrap gap-4 text-sm mb-3">
                    <span className="font-semibold">Estudantes, orientadores e coorientadores de:</span>
                    <label className="inline-flex items-center gap-2">
                        <input type="radio" name="escopo" checked={escopo === 'finalistas'} onChange={() => setEscopo('finalistas')} />
                        só os finalistas
                    </label>
                    <label className="inline-flex items-center gap-2">
                        <input type="radio" name="escopo" checked={escopo === 'submetidos'} onChange={() => setEscopo('submetidos')} />
                        todos os projetos submetidos
                    </label>
                </div>
                {escopo === 'finalistas' && opcoes && !opcoes.tem_lista_final && (
                    <div className="mb-3"><Alert>Não há lista final publicada: com "só os finalistas", nenhum estudante, orientador ou coorientador entra.</Alert></div>
                )}
                <Baixar
                    ocupado={ocupado?.startsWith('pa-') ? ocupado.slice(3) : null}
                    onBaixar={(f) => (grupos.length === 0 ? setErro('Marque ao menos um grupo.') : baixar(`pa-${f}`, () => baixarParticipantesCertificado(grupos, escopo, f)))}
                />
            </section>

            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 max-w-4xl" aria-label="Projetos avaliados por avaliador">
                <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Projetos avaliados por avaliador</h2>
                <p className="text-sm text-on-surface-variant mb-3">
                    Uma linha por avaliação concluída, com o título do projeto — para responder de uma vez a quem pede
                    a declaração nominal. O relatório de uma pessoa só está em "Projetos avaliados", na tabela acima.
                </p>
                <div className="flex flex-wrap items-end gap-3">
                    <Select aria-label="Fase da planilha nominal" value={faseNominal} onChange={(e) => setFaseNominal(e.target.value)}>
                        {(opcoes?.fases ?? []).map((f) => <option key={f.valor} value={f.valor}>{f.rotulo}</option>)}
                    </Select>
                    <Baixar
                        ocupado={ocupado?.startsWith('no-') ? ocupado.slice(3) : null}
                        onBaixar={(f) => baixar(`no-${f}`, () => baixarAvaliacoesNominais(faseNominal, f))}
                    />
                </div>
            </section>
        </AppShell>
    );
}
