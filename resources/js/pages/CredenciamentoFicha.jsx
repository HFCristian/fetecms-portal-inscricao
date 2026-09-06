import { useCallback, useEffect, useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getFichaCredenciamento,
    credenciarProjeto,
    cancelarCredenciamento,
    registrarRetiradaKit,
    salvarRascunhoCredenciamento,
    assumirCredenciamento,
} from '../lib/credenciamento.js';
import { useModoTeste } from '../lib/modoTeste.js';

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

/** "agora" no formato do <input type="datetime-local">, em hora local. */
function agoraLocal() {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

/** Um ISO do servidor no formato do input (hora local do navegador). */
function paraInput(iso) {
    if (!iso) return agoraLocal();
    const d = new Date(iso);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

/** Chave de uma marcação: documento + pessoa. */
const chave = (documentoId, tipo, pessoaId) => `${documentoId}:${tipo}:${pessoaId}`;

/** Chave de uma pessoa do projeto (presença e kit). */
const chavePessoa = (pessoa) => `${pessoa.tipo}:${pessoa.id}`;

/**
 * Ficha de credenciamento de um finalista.
 *
 * Cada pessoa do projeto — alunos, orientador e coorientador — aparece com os
 * documentos que o papel dela exige, e cada documento é marcado como
 * **presente**, **ausente** ou **não necessário**. "Não necessário" é uma
 * decisão registrada, diferente de deixar em branco.
 *
 * O atendimento pode ser **salvo como rascunho**: o aluno esqueceu o RG no
 * ônibus e sai para buscar, e o balcão guarda o que já conferiu em vez de
 * recomeçar. O rascunho tem dono — quem o abriu —, e continuar o de outra
 * pessoa exige ser admin permanente; o de outro permanente, ainda por cima,
 * pede **justificativa**.
 *
 * Antes dos documentos vem a **presença**: quem não apareceu é marcado ausente
 * e some com a lista de documentos dela — não se confere o RG de quem não veio
 * —, e o projeto é credenciado assim mesmo. A pessoa que chegar depois é
 * conferida numa segunda passada.
 *
 * O **kit é por pessoa** e sai no nome de um responsável, que precisa ser gente
 * do projeto: um aluno leva o dele e o de dois colegas, e quem sobrou retira
 * mais tarde. Por isso, com o credenciamento já concluído, a retirada tem botão
 * próprio — ela só acrescenta, então vale inclusive para quem não credenciou o
 * projeto (uma conta de balcão no turno seguinte, por exemplo).
 *
 * O **horário de início** já vem preenchido com o momento do atendimento, mas
 * pode ser corrigido — é assim que se lança um credenciamento que aconteceu
 * antes. Quando ele é alterado, o fim passa a ser início + 5 minutos, em vez do
 * instante da conclusão.
 */
export default function CredenciamentoFicha() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [teste] = useModoTeste();
    const [dados, setDados] = useState(null);
    const [marcacoes, setMarcacoes] = useState({});
    // Presença por pessoa ("tipo:id" => bool) e o que está sendo entregue agora.
    const [presencas, setPresencas] = useState({});
    const [kitsSelecionados, setKitsSelecionados] = useState([]);
    const [responsavelKit, setResponsavelKit] = useState('');
    const [entregandoKit, setEntregandoKit] = useState(false);
    // Assumir o rascunho de outro admin permanente pede justificativa.
    const [assumindo, setAssumindo] = useState(false);
    const [motivoAssumir, setMotivoAssumir] = useState('');
    const [observacao, setObservacao] = useState('');
    const [erro, setErro] = useState('');
    const [salvando, setSalvando] = useState(false);
    // Diálogo de cancelamento: a justificativa é obrigatória e vai para a trilha.
    const [cancelando, setCancelando] = useState(false);
    const [justificativa, setJustificativa] = useState('');
    // Horário sugerido x horário na tela: só mandamos o início quando ele muda,
    // e é essa mudança que faz o fim virar "início + 5 minutos".
    const [inicio, setInicio] = useState(agoraLocal());
    const [inicioSugerido, setInicioSugerido] = useState(agoraLocal());
    const [entregar, setEntregar] = useState(null);

    const carregar = useCallback(() => {
        getFichaCredenciamento(id, teste)
            .then((d) => {
                setDados(d);
                setObservacao(d.credenciamento?.observacao ?? '');
                // Pré-carrega o que já foi conferido antes.
                const atual = {};
                for (const pessoa of d.pessoas) {
                    for (const doc of pessoa.documentos) {
                        if (doc.situacao) atual[chave(doc.id, pessoa.tipo, pessoa.id)] = doc.situacao;
                    }
                }
                setMarcacoes(atual);
                setPresencas(Object.fromEntries(d.pessoas.map((p) => [chavePessoa(p), p.presente !== false])));
                setKitsSelecionados([]);
                setResponsavelKit('');
                const sugerido = paraInput(d.credenciamento?.iniciado_em);
                setInicio(sugerido);
                setInicioSugerido(sugerido);
                setErro('');
            })
            .catch(() => setErro('Não foi possível carregar a ficha.'));
    }, [id, teste]);

    useEffect(() => { carregar(); }, [carregar]);

    function marcar(documentoId, pessoa, situacao) {
        setMarcacoes((m) => ({ ...m, [chave(documentoId, pessoa.tipo, pessoa.id)]: situacao }));
    }

    function definirPresenca(pessoa, presente) {
        setPresencas((p) => ({ ...p, [chavePessoa(pessoa)]: presente }));
    }

    function alternarKit(pessoa) {
        const k = chavePessoa(pessoa);
        setKitsSelecionados((atual) => (atual.includes(k) ? atual.filter((i) => i !== k) : [...atual, k]));
    }

    /** As pessoas escolhidas agora, no formato que a API espera. */
    function kitsParaEnvio() {
        return kitsSelecionados.map((k) => {
            const [pessoaTipo, pessoaId] = k.split(':');
            return { pessoa_tipo: pessoaTipo, pessoa_id: pessoaId === 'null' ? null : Number(pessoaId) };
        });
    }

    /** Retirada avulsa: o credenciamento já está concluído e alguém voltou ao balcão. */
    async function entregarKits() {
        setEntregandoKit(true);
        setErro('');
        try {
            const [responsavelTipo, responsavelId] = responsavelKit.split(':');
            await registrarRetiradaKit(id, {
                responsavel_tipo: responsavelTipo,
                responsavel_id: responsavelId === 'null' ? null : Number(responsavelId),
                pessoas: kitsParaEnvio(),
            }, teste);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível registrar a retirada.');
        } finally {
            setEntregandoKit(false);
            carregar();
        }
    }

    async function cancelar() {
        setSalvando(true);
        setErro('');
        try {
            await cancelarCredenciamento(id, justificativa.trim(), teste);
            navigate('/admin/credenciamento/credenciar', { replace: true });
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível cancelar o credenciamento.');
            setCancelando(false);
            carregar();
        } finally {
            setSalvando(false);
        }
    }

    /** Guarda o que já foi conferido sem fechar o atendimento. */
    async function salvarRascunho() {
        setSalvando(true);
        setErro('');
        try {
            await salvarRascunhoCredenciamento(id, payloadDaConferencia(), teste);
            navigate('/admin/credenciamento/credenciar', { replace: true });
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível salvar o rascunho.');
            carregar();
        } finally {
            setSalvando(false);
        }
    }

    /** Assume o atendimento que outra pessoa deixou em aberto. */
    async function assumir() {
        setSalvando(true);
        setErro('');
        try {
            await assumirCredenciamento(id, motivoAssumir.trim(), teste);
            setAssumindo(false);
            carregar();
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível assumir o atendimento.');
        } finally {
            setSalvando(false);
        }
    }

    /**
     * O que a conferência manda ao servidor — o mesmo corpo para o rascunho e
     * para a conclusão: o que muda é o que o servidor faz com ele.
     */
    function payloadDaConferencia() {
        const payload = {
            marcacoes: Object.entries(marcacoes).map(([k, situacao]) => {
                const [documentoId, pessoaTipo, pessoaId] = k.split(':');
                return {
                    documento_id: Number(documentoId),
                    pessoa_tipo: pessoaTipo,
                    pessoa_id: pessoaId === 'null' ? null : Number(pessoaId),
                    situacao,
                };
            }),
            pessoas: dados.pessoas.map((p) => ({
                pessoa_tipo: p.tipo,
                pessoa_id: p.id,
                presente: presencas[chavePessoa(p)] !== false,
            })),
            observacao: observacao.trim() === '' ? null : observacao.trim(),
            // Só viaja quando o admin corrigiu o horário: aí o fim é calculado.
            iniciado_em: inicio !== inicioSugerido ? inicio : null,
        };

        // Kits escolhidos durante o próprio atendimento saem junto.
        if (kitsSelecionados.length > 0 && responsavelKit !== '') {
            const [responsavelTipo, responsavelId] = responsavelKit.split(':');
            payload.kits = {
                responsavel_tipo: responsavelTipo,
                responsavel_id: responsavelId === 'null' ? null : Number(responsavelId),
                pessoas: kitsParaEnvio(),
            };
        }

        return payload;
    }

    async function concluir() {
        setSalvando(true);
        setErro('');
        try {
            await credenciarProjeto(id, payloadDaConferencia(), teste);

            // Lembrete dos itens a entregar antes de sair da ficha.
            if ((dados.config?.itens ?? []).length > 0) setEntregar(dados.config.itens);
            else navigate('/admin/credenciamento/credenciados', { replace: true });
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível concluir o credenciamento.');
            carregar();
        } finally {
            setSalvando(false);
        }
    }

    if (dados === null) {
        return (
            <AppShell>
                {erro ? <Alert>{erro}</Alert> : (
                    <div className="text-center py-10 text-on-surface-variant">
                        <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                    </div>
                )}
            </AppShell>
        );
    }

    const { projeto, pessoas, credenciamento, situacoes, config } = dados;
    // Credenciamento de outra conta só é alterado por admin permanente — o
    // servidor barra de qualquer forma, aqui é para a tela não mentir.
    const podeAlterar = credenciamento?.pode_alterar !== false;
    // Rascunho de outro admin permanente: só depois de assumir, com motivo.
    const precisaAssumir = Boolean(credenciamento?.exige_justificativa);
    const emRascunho = Boolean(credenciamento?.em_rascunho);
    const aberto = config?.aberto && podeAlterar && !precisaAssumir;
    // A retirada de kit só depende da janela do evento: ela acrescenta, nunca
    // reescreve a conferência de outra conta.
    const janelaAberta = Boolean(config?.aberto);
    const concluido = Boolean(credenciamento?.concluido);
    // Sem nenhum documento cadastrado não há o que conferir — a lista é parametrizável.
    const semDocumentos = pessoas.every((p) => p.documentos.length === 0);

    return (
        <AppShell>
            <Link to="/admin/credenciamento/credenciar" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Credenciar
            </Link>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">{projeto.titulo}</h1>
            <p className="text-sm text-on-surface-variant mb-6">
                {[projeto.categoria, projeto.area, projeto.escola].filter(Boolean).join(' · ') || '—'}
            </p>

            {erro && <div className="mb-4"><Alert>{erro}</Alert></div>}

            {/* A ficha é onde o credenciamento é de fato gravado — é a tela em que
                confundir ensaio com realidade custaria mais caro. */}
            {config?.lista?.demo && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        <strong>Modo demo</strong> — este é um projeto fictício. A conferência é
                        gravada, mas nenhum finalista de verdade é credenciado.
                    </Alert>
                </div>
            )}

            {credenciamento?.concluido && (
                <div className="mb-4 max-w-3xl">
                    <Alert type={podeAlterar ? 'info' : 'error'}>
                        Credenciado em {dataHora(credenciamento.finalizado_em)}
                        {credenciamento.credenciado_por ? ` por ${credenciamento.credenciado_por}` : ''}.
                        {' '}
                        {podeAlterar
                            ? 'Uma nova conclusão substitui a conferência anterior.'
                            : credenciamento.motivo_bloqueio}
                    </Alert>
                </div>
            )}

            {emRascunho && !podeAlterar && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="error">{credenciamento.motivo_bloqueio}</Alert>
                </div>
            )}

            {precisaAssumir && (
                <div className="mb-4 max-w-3xl space-y-2">
                    <Alert type="error">
                        Atendimento em rascunho, iniciado por{' '}
                        <strong>{credenciamento.iniciado_por}</strong>. Para continuar a conferência
                        dele, assuma o atendimento informando o motivo — a troca fica registrada.
                    </Alert>
                    <Button type="button" variant="outline" onClick={() => { setMotivoAssumir(''); setAssumindo(true); }}>
                        <span className="material-symbols-outlined text-[20px]">how_to_reg</span>
                        Assumir atendimento
                    </Button>
                </div>
            )}

            {emRascunho && podeAlterar && !precisaAssumir && !credenciamento.meu_rascunho && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        Atendimento em rascunho, iniciado por{' '}
                        <strong>{credenciamento.iniciado_por}</strong> (conta temporária). Ao salvar
                        ou concluir, ele passa para você — e a troca fica registrada.
                    </Alert>
                </div>
            )}

            {emRascunho && credenciamento.meu_rascunho && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        Seu atendimento em rascunho, desde {dataHora(credenciamento.iniciado_em)}.
                        Conclua quando a equipe voltar com o que faltou.
                    </Alert>
                </div>
            )}

            {!aberto && !precisaAssumir && podeAlterar && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        O credenciamento está fechado agora. A ficha abre em leitura.
                    </Alert>
                </div>
            )}

            {semDocumentos && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        Nenhum documento cadastrado em Parametrização → Credenciamento. Você ainda pode
                        concluir o credenciamento, mas não haverá conferência registrada.
                    </Alert>
                </div>
            )}

            <div className="space-y-4 max-w-3xl">
                {pessoas.map((pessoa) => (
                    <section key={`${pessoa.tipo}:${pessoa.id}`} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                        <div className="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                            <div className="flex items-baseline gap-2">
                                <h2 className="font-display text-primary font-semibold">{pessoa.nome}</h2>
                                <span className="text-xs text-on-surface-variant">{pessoa.tipo_label}</span>
                            </div>
                            {/* Presença antes de tudo: quem não veio não tem documento a conferir. */}
                            <div className="flex gap-1" role="group" aria-label={`Presença de ${pessoa.nome}`}>
                                {/* "Compareceu/Faltou" e não "Presente/Ausente": estes últimos já
                                    são os rótulos de cada documento, logo abaixo, e a mesma palavra
                                    querendo dizer duas coisas no mesmo cartão confunde no balcão. */}
                                {[
                                    { valor: true, rotulo: 'Compareceu' },
                                    { valor: false, rotulo: 'Faltou' },
                                ].map((opcao) => {
                                    const ativo = (presencas[chavePessoa(pessoa)] !== false) === opcao.valor;
                                    return (
                                        <button
                                            key={opcao.rotulo}
                                            type="button"
                                            disabled={!aberto}
                                            aria-pressed={ativo}
                                            onClick={() => definirPresenca(pessoa, opcao.valor)}
                                            className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors disabled:opacity-50 ${ativo
                                                ? (opcao.valor
                                                    ? 'bg-primary-container text-on-primary border-primary-container'
                                                    : 'bg-error-container text-on-error-container border-error-container')
                                                : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'}`}
                                        >
                                            {opcao.rotulo}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {presencas[chavePessoa(pessoa)] === false ? (
                            <p className="text-sm text-on-surface-variant">
                                Faltou ao credenciamento — os documentos desta pessoa não precisam ser
                                conferidos. Se ela aparecer depois, marque “Compareceu” e confira nesta
                                mesma ficha.
                            </p>
                        ) : pessoa.documentos.length === 0 ? (
                            <p className="text-sm text-on-surface-variant">
                                Nenhum documento exigido deste papel.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {pessoa.documentos.map((doc) => (
                                    <li key={doc.id} className="flex flex-col sm:flex-row sm:items-center gap-2">
                                        <span className="text-sm text-on-surface flex-1 min-w-0">{doc.nome}</span>
                                        <div className="flex flex-wrap gap-1" role="group" aria-label={`${doc.nome} de ${pessoa.nome}`}>
                                            {situacoes.map((s) => {
                                                const ativo = marcacoes[chave(doc.id, pessoa.tipo, pessoa.id)] === s.value;
                                                return (
                                                    <button
                                                        key={s.value}
                                                        type="button"
                                                        disabled={!aberto}
                                                        aria-pressed={ativo}
                                                        onClick={() => marcar(doc.id, pessoa, s.value)}
                                                        className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors disabled:opacity-50 ${ativo
                                                            ? 'bg-primary-container text-on-primary border-primary-container'
                                                            : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'}`}
                                                    >
                                                        {s.label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                ))}

                {/* Kits: um por pessoa, retirados no nome de quem veio ao balcão. */}
                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                    <h2 className="font-display text-primary font-semibold mb-1">Retirada dos kits</h2>
                    <p className="text-sm text-on-surface-variant mb-3">
                        Marque de quem os kits estão sendo retirados agora e quem está levando. Quem
                        ficar de fora pode retirar o seu depois, com outro responsável e outro horário.
                    </p>

                    <ul className="space-y-2 mb-4">
                        {pessoas.map((pessoa) => {
                            const k = chavePessoa(pessoa);
                            const retirado = pessoa.kit?.retirado;

                            return (
                                <li key={k} className="flex flex-wrap items-center gap-2">
                                    <label className="flex items-center gap-2 flex-1 min-w-0">
                                        <input
                                            type="checkbox"
                                            className="accent-primary-container"
                                            disabled={retirado || !janelaAberta}
                                            checked={retirado || kitsSelecionados.includes(k)}
                                            onChange={() => alternarKit(pessoa)}
                                            aria-label={`Kit de ${pessoa.nome}`}
                                        />
                                        <span className="text-sm text-on-surface truncate">
                                            {pessoa.nome}
                                            <span className="text-on-surface-variant"> · {pessoa.tipo_label}</span>
                                        </span>
                                    </label>
                                    <span className="text-xs text-on-surface-variant">
                                        {retirado
                                            ? `Retirado por ${pessoa.kit.por_nome} em ${dataHora(pessoa.kit.em)}`
                                            : 'Kit pendente'}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>

                    <div className="flex flex-wrap items-end gap-3">
                        <label className="block grow max-w-xs">
                            <span className="text-sm font-semibold text-on-surface">Quem está retirando</span>
                            <select
                                value={responsavelKit}
                                onChange={(e) => setResponsavelKit(e.target.value)}
                                disabled={!janelaAberta}
                                aria-label="Quem está retirando os kits"
                                className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20 disabled:opacity-60"
                            >
                                <option value="">Escolha uma pessoa do projeto</option>
                                {pessoas.map((pessoa) => (
                                    <option key={chavePessoa(pessoa)} value={chavePessoa(pessoa)}>
                                        {pessoa.nome} · {pessoa.tipo_label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        {/* Com o credenciamento já concluído a retirada vale sozinha: é a
                            segunda visita ao balcão, e ela só acrescenta. Antes disso, ela
                            sai junto da conclusão. */}
                        {concluido ? (
                            <Button
                                type="button"
                                variant="outline"
                                loading={entregandoKit}
                                disabled={!janelaAberta || kitsSelecionados.length === 0 || responsavelKit === ''}
                                onClick={entregarKits}
                            >
                                <span className="material-symbols-outlined text-[20px]">local_mall</span>
                                Registrar retirada
                            </Button>
                        ) : (
                            <p className="text-xs text-on-surface-variant pb-2">
                                A retirada é gravada junto da conclusão do credenciamento.
                            </p>
                        )}
                    </div>
                </section>

                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                    <label className="block mb-4 max-w-xs">
                        <span className="text-sm font-semibold text-on-surface">Início do atendimento</span>
                        <input
                            type="datetime-local"
                            value={inicio}
                            onChange={(e) => setInicio(e.target.value)}
                            disabled={!aberto}
                            aria-label="Início do atendimento"
                            className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20 disabled:opacity-60"
                        />
                        <span className="text-xs text-on-surface-variant">
                            Vem preenchido com o horário de agora. Corrigindo-o, o fim do
                            credenciamento passa a ser {config?.minutos_atendimento ?? 5} minutos depois do início.
                        </span>
                    </label>

                    <label className="block">
                        <span className="text-sm font-semibold text-on-surface">Observação (opcional)</span>
                        <textarea
                            value={observacao}
                            onChange={(e) => setObservacao(e.target.value)}
                            rows={3}
                            maxLength={1000}
                            disabled={!aberto}
                            placeholder="Ex.: orientador vai trazer o documento até o fim do dia."
                            className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20 disabled:opacity-60"
                        />
                    </label>
                </section>

                <div className="flex flex-wrap justify-end gap-3">
                    <Button type="button" variant="outline" onClick={() => navigate('/admin/credenciamento/credenciar')}>
                        Voltar
                    </Button>
                    {concluido && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={!aberto}
                            onClick={() => { setJustificativa(''); setCancelando(true); }}
                        >
                            <span className="material-symbols-outlined text-[20px]">undo</span>
                            Cancelar credenciamento
                        </Button>
                    )}
                    {/* Rascunho: guarda o que já foi conferido sem fechar. Não
                        faz sentido para quem já está credenciado. */}
                    {!concluido && (
                        <Button type="button" variant="outline" loading={salvando} disabled={!aberto} onClick={salvarRascunho}>
                            <span className="material-symbols-outlined text-[20px]">save</span>
                            Salvar rascunho
                        </Button>
                    )}
                    <Button type="button" variant="success" loading={salvando} disabled={!aberto} onClick={concluir}>
                        <span className="material-symbols-outlined text-[20px]">how_to_reg</span>
                        {concluido ? 'Regravar credenciamento' : 'Concluir credenciamento'}
                    </Button>
                </div>
            </div>

            {cancelando && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                    <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                        <h3 className="font-display text-lg font-semibold text-on-surface">Cancelar credenciamento</h3>
                        <p className="text-sm text-on-surface-variant">
                            A conferência dos documentos é apagada e o projeto volta para a fila de
                            <strong> Credenciar</strong>. A justificativa entra em Registros → Credenciamento.
                        </p>
                        <label className="block">
                            <span className="text-sm font-semibold text-on-surface">Justificativa</span>
                            <textarea
                                aria-label="Justificativa do cancelamento"
                                value={justificativa}
                                onChange={(e) => setJustificativa(e.target.value)}
                                rows={3}
                                maxLength={500}
                                placeholder="Ex.: credenciado por engano, o projeto é de outra equipe."
                                className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                            />
                        </label>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setCancelando(false)}>Voltar</Button>
                            <Button
                                type="button"
                                loading={salvando}
                                disabled={justificativa.trim().length < 5}
                                onClick={cancelar}
                            >
                                Cancelar credenciamento
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {assumindo && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                    <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                        <h3 className="font-display text-lg font-semibold text-on-surface">Assumir atendimento</h3>
                        <p className="text-sm text-on-surface-variant">
                            A conferência que <strong>{credenciamento?.iniciado_por}</strong> começou passa
                            para você. O motivo entra em Registros → Credenciamento.
                        </p>
                        <label className="block">
                            <span className="text-sm font-semibold text-on-surface">Justificativa</span>
                            <textarea
                                aria-label="Justificativa para assumir o atendimento"
                                value={motivoAssumir}
                                onChange={(e) => setMotivoAssumir(e.target.value)}
                                rows={3}
                                maxLength={500}
                                placeholder="Ex.: o colega saiu do evento e a equipe está esperando."
                                className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                            />
                        </label>
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setAssumindo(false)}>Voltar</Button>
                            <Button
                                type="button"
                                loading={salvando}
                                disabled={motivoAssumir.trim().length < 5}
                                onClick={assumir}
                            >
                                Assumir
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {/* Lembrete de entrega: os itens da parametrização, antes de sair da ficha. */}
            {entregar && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                    <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4">
                        <div className="flex items-start gap-3">
                            <span className="material-symbols-outlined text-secondary text-[28px]" style={{ fontVariationSettings: "'FILL' 1" }}>
                                check_circle
                            </span>
                            <div>
                                <h3 className="font-display text-lg font-semibold text-on-surface">Credenciamento concluído</h3>
                                <p className="text-sm text-on-surface-variant">
                                    Lembre-se de entregar aos finalistas:
                                </p>
                            </div>
                        </div>
                        <ul className="list-disc pl-6 text-sm text-on-surface space-y-1">
                            {entregar.map((item) => <li key={item}>{item}</li>)}
                        </ul>
                        <div className="flex justify-end">
                            <Button
                                type="button"
                                onClick={() => navigate('/admin/credenciamento/credenciados', { replace: true })}
                            >
                                Entendi
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    );
}
