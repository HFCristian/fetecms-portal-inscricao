import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, useConfirm } from '../components/ui.jsx';
import FiltroRecomendacoes, { SEM_RECORTE } from '../components/FiltroRecomendacoes.jsx';
import CartaoCredencial from '../components/CartaoCredencial.jsx';
import FormularioCredencial from '../components/FormularioCredencial.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getCredenciais, criarCredencial, atualizarCredencial, excluirCredencial,
    atribuirCredencial, retirarCredencial, baixarPremiacao,
} from '../lib/presencial.js';
import { decidirRecomendacao } from '../lib/recomendacoes.js';

/**
 * Avaliação presencial → **Credenciais e Prêmios**.
 *
 * Credencial é a vaga que a feira tem para dar e o projeto leva embora: a
 * indicação a uma feira nacional, a bolsa de um parceiro. **Prêmio** é o que
 * se anuncia no palco e não vira crachá — o destaque, a menção honrosa. Os
 * dois se cadastram aqui, anexam-se a projetos **finalistas** e saem juntos na
 * **lista de premiação** que se lê na cerimônia.
 *
 * A distinção só pesa no cerimonial: quem recebe qualquer um dos dois é
 * **premiado** (e rende medalha), mas só a credencial entra no card de
 * *credenciais a separar*, porque só ela tem objeto na mesa.
 *
 * Cada cartão tem **Editar**, com o mesmo formulário do cadastro: o item
 * inteiro muda, e as vagas descem até o número de projetos que já o
 * receberam.
 */
export default function PresencialCredenciais() {
    const [credenciais, setCredenciais] = useState(null);
    const [candidatos, setCandidatos] = useState([]);
    const [tipos, setTipos] = useState([{ value: 'credencial', label: 'Credencial', descricao: '' }]);
    const [criando, setCriando] = useState(false);
    const [ocupado, setOcupado] = useState(false);
    // O recorte das recomendações do comitê dentro dos cartões (Sprint 201).
    const [recorte, setRecorte] = useState(SEM_RECORTE);
    const [resumoRecomendacoes, setResumoRecomendacoes] = useState(null);
    const [confirmar, dialogoConfirmacao] = useConfirm();
    // Os erros de campo e de que formulário vieram: o do cadastro ('novo') ou
    // o de edição de um cartão (o id dele).
    const [errors, setErrors] = useState({});
    const [errosDe, setErrosDe] = useState(null);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');

    const carregar = useCallback(() => getCredenciais(recorte)
        .then((r) => {
            setCredenciais(r.data);
            setCandidatos(r.meta?.candidatos ?? []);
            setResumoRecomendacoes(r.meta?.recomendacoes ?? null);
            if (r.meta?.tipos?.length) setTipos(r.meta.tipos);
        })
        .catch(() => { setCredenciais([]); setAlerta('Não foi possível carregar as credenciais e prêmios.'); }), [recorte]);

    useEffect(() => { carregar(); }, [carregar]);

    /** Executa e recarrega; devolve se deu certo, para o formulário saber se fecha. */
    async function acao(fn, mensagem = '', origem = null) {
        setOcupado(true); setAlerta(''); setSucesso(''); setErrors({}); setErrosDe(origem);
        try {
            await fn();
            await carregar();
            if (mensagem) setSucesso(mensagem);
            return true;
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErrors(fields ?? {});
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
            return false;
        } finally {
            setOcupado(false);
        }
    }

    const errosDoFormulario = (origem) => (errosDe === origem ? errors : {});

    return (
        <AppShell>
            <Link to="/admin/presencial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Avaliação presencial
            </Link>
            <div className="flex flex-wrap items-start justify-between gap-3 mb-1 max-w-3xl">
                <h1 className="font-display text-2xl font-semibold text-primary">Credenciais e Prêmios</h1>
                <Button type="button" variant="outline" onClick={() => baixarPremiacao()}>
                    <span className="material-symbols-outlined text-[20px]">download</span>
                    Lista de premiação (TXT)
                </Button>
            </div>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                O que a feira tem para dar. <strong>Credencial</strong> é a vaga que o projeto
                leva — indicação a outra feira, bolsa, convite de parceiro —, e é dela que sai a
                pilha a separar no cerimonial. <strong>Prêmio</strong> é o reconhecimento
                anunciado no palco, sem crachá a entregar. Anexe cada um a um projeto{' '}
                <strong>finalista</strong> e baixe a lista que se lê na cerimônia.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            {criando ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 mb-6 max-w-3xl">
                    <FormularioCredencial
                        titulo="Novo item"
                        rotuloSalvar="Cadastrar"
                        tipos={tipos}
                        erros={errosDoFormulario('novo')}
                        ocupado={ocupado}
                        onCancelar={() => { setCriando(false); setErrors({}); }}
                        onSalvar={async (dados) => {
                            const ok = await acao(
                                () => criarCredencial(dados),
                                dados.tipo === 'premio' ? 'Prêmio cadastrado.' : 'Credencial cadastrada.',
                                'novo',
                            );
                            if (ok) setCriando(false);
                        }}
                    />
                </div>
            ) : (
                <div className="mb-6">
                    <Button type="button" onClick={() => { setCriando(true); setAlerta(''); setSucesso(''); }}>
                        <span className="material-symbols-outlined text-[20px]">add</span>
                        Nova credencial ou prêmio
                    </Button>
                </div>
            )}

            <FiltroRecomendacoes
                valor={recorte}
                onChange={setRecorte}
                opcoes={resumoRecomendacoes?.opcoes}
                pendentes={resumoRecomendacoes?.pendentes ?? 0}
            />

            {credenciais === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : credenciais.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    Nenhuma credencial ou prêmio cadastrado nesta edição.
                </div>
            ) : (
                <ul className="space-y-3 max-w-3xl">
                    {credenciais.map((c) => (
                        <CartaoCredencial
                            key={c.id}
                            credencial={c}
                            candidatos={candidatos}
                            ocupado={ocupado}
                            onAtribuir={(cred, projeto) => acao(
                                () => atribuirCredencial(cred.id, projeto.id),
                                'Anexado ao projeto.',
                            )}
                            onRetirar={(cred, projeto) => acao(
                                () => retirarCredencial(cred.id, projeto.id),
                                'Retirado do projeto.',
                            )}
                            onAlternar={(cred) => acao(() => atualizarCredencial(cred.id, { ativa: !cred.ativa }))}
                            onDecidir={(ids, status, r) => acao(
                                () => Promise.all(ids.map((id) => decidirRecomendacao(id, status))),
                                `Recomendação de ${r.projeto} ${status === 'aprovada' ? 'aprovada' : 'rejeitada'}.`,
                            )}
                            onCancelarPremiacao={async (cred, projeto) => {
                                const ok = await confirmar({
                                    title: 'Cancelar premiação',
                                    message: `${cred.nome} sai de ${projeto.titulo} e a vaga volta. A recomendação continua aprovada.`,
                                    confirmLabel: 'Cancelar premiação',
                                    cancelLabel: 'Voltar',
                                    danger: true,
                                });
                                if (ok) acao(() => retirarCredencial(cred.id, projeto.id), 'Premiação cancelada.');
                            }}
                            tipos={tipos}
                            errosEdicao={errosDoFormulario(c.id)}
                            onEditar={(cred, dados) => acao(
                                () => atualizarCredencial(cred.id, dados),
                                dados.tipo === 'premio' ? 'Prêmio atualizado.' : 'Credencial atualizada.',
                                cred.id,
                            )}
                            onExcluir={(cred) => acao(() => excluirCredencial(cred.id), 'Item excluído.')}
                        />
                    ))}
                </ul>
            )}
            {dialogoConfirmacao}
        </AppShell>
    );
}
