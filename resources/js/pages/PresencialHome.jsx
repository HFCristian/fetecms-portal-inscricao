import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getConfigPresencial, definirInformacoesPresencial,
    getItensChecagem, criarItemChecagem, atualizarItemChecagem, excluirItemChecagem,
} from '../lib/presencial.js';

const campoClass =
    'w-full bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface ' +
    'focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none';

const SECOES = [
    {
        to: '/admin/presencial/checagem',
        icon: 'checklist',
        titulo: 'Checagem de estandes',
        descricao: 'Percorra os estandes dos finalistas e registre o que encontrou em cada um.',
    },
    {
        to: '/admin/presencial/voluntarios',
        icon: 'volunteer_activism',
        titulo: 'Voluntários',
        descricao: 'Contas de prazo curto para quem trabalha no evento, com os turnos de cada um.',
    },
    {
        to: '/admin/presencial/espelho',
        icon: 'table_view',
        titulo: 'Espelho da checagem',
        descricao: 'Todos os finalistas lado a lado, com o que foi conferido e o termo de responsabilidade.',
    },
];

/** O catálogo do que se confere em cada estande. */
function Itens() {
    const [itens, setItens] = useState(null);
    const [nome, setNome] = useState('');
    const [descricao, setDescricao] = useState('');
    const [erro, setErro] = useState('');
    const [salvando, setSalvando] = useState(false);

    useEffect(() => { getItensChecagem().then(setItens).catch(() => setItens([])); }, []);

    async function acao(fn) {
        setSalvando(true); setErro('');
        try {
            setItens(await fn());
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível concluir.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 max-w-3xl">
            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">
                Itens conferidos no estande
            </h2>
            <p className="text-sm text-on-surface-variant mb-3">
                O que a equipe confere em cada estande. Item já usado em alguma checagem não pode
                ser excluído — <strong>desative-o</strong>, e ele some das fichas novas sem apagar
                as antigas.
            </p>

            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}

            <div className="flex flex-col sm:flex-row gap-2 mb-4">
                <input
                    className={campoClass}
                    placeholder="Nome do item (ex.: Banner montado)"
                    aria-label="Nome do item"
                    value={nome}
                    onChange={(e) => setNome(e.target.value)}
                />
                <input
                    className={campoClass}
                    placeholder="Descrição (opcional)"
                    aria-label="Descrição do item"
                    value={descricao}
                    onChange={(e) => setDescricao(e.target.value)}
                />
                <Button
                    type="button"
                    loading={salvando}
                    disabled={nome.trim() === ''}
                    onClick={() => acao(async () => {
                        const novos = await criarItemChecagem({ nome: nome.trim(), descricao: descricao.trim() || null });
                        setNome(''); setDescricao('');
                        return novos;
                    })}
                >
                    Adicionar
                </Button>
            </div>

            {itens === null ? (
                <p className="text-sm text-on-surface-variant">Carregando…</p>
            ) : itens.length === 0 ? (
                <p className="text-sm text-on-surface-variant">
                    Nenhum item cadastrado ainda — sem eles a ficha do estande fica só com o termo.
                </p>
            ) : (
                <ul className="divide-y divide-outline-variant/30">
                    {itens.map((i) => (
                        <li key={i.id} className="py-3 flex flex-wrap items-center gap-3">
                            <div className="min-w-0 flex-1">
                                <p className={`text-sm ${i.ativo ? 'text-on-surface' : 'text-on-surface-variant line-through'}`}>
                                    {i.nome}
                                </p>
                                {i.descricao && <p className="text-xs text-on-surface-variant">{i.descricao}</p>}
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={salvando}
                                onClick={() => acao(() => atualizarItemChecagem(i.id, { ativo: !i.ativo }))}
                            >
                                {i.ativo ? 'Desativar' : 'Reativar'}
                            </Button>
                            {!i.em_uso && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-error border-error/40 hover:bg-error-container/40"
                                    disabled={salvando}
                                    onClick={() => acao(() => excluirItemChecagem(i.id))}
                                >
                                    Excluir
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** As orientações que o avaliador presencial lê depois de aceitar. */
function Orientacoes({ inicial }) {
    const [texto, setTexto] = useState(inicial ?? '');
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');
    const [sucesso, setSucesso] = useState('');

    async function salvar() {
        setSalvando(true); setErro(''); setSucesso('');
        try {
            const resp = await definirInformacoesPresencial(texto.trim() || null);
            setSucesso(resp.meta?.message ?? 'Orientações salvas.');
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 max-w-3xl">
            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">
                Orientações ao avaliador presencial
            </h2>
            <p className="text-sm text-on-surface-variant mb-3">
                Este texto aparece na aba <strong>Presencial</strong> de quem aceitou avaliar no dia
                da feira — local, horário de chegada, o que levar. Quem recusou não o vê.
            </p>

            {erro && <div className="mb-3"><Alert>{erro}</Alert></div>}
            {sucesso && <div className="mb-3"><Alert type="info">{sucesso}</Alert></div>}

            <textarea
                rows={6}
                maxLength={5000}
                className={campoClass}
                aria-label="Orientações ao avaliador presencial"
                value={texto}
                onChange={(e) => setTexto(e.target.value)}
            />
            <div className="flex justify-end mt-3">
                <Button type="button" loading={salvando} onClick={salvar}>Salvar orientações</Button>
            </div>
        </section>
    );
}

/**
 * Aba "Avaliação presencial": o que a organização faz no **dia da feira** —
 * conferir os estandes, cadastrar quem trabalha no evento e registrar as
 * credenciais de premiação.
 *
 * A janela é a mesma do credenciamento e do almoxarifado (as datas do evento):
 * é tudo trabalho de campo, e fora dela as seções abrem em leitura.
 */
export default function PresencialHome() {
    const [config, setConfig] = useState(null);

    useEffect(() => { getConfigPresencial().then(setConfig).catch(() => setConfig({})); }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Avaliação presencial</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                O dia da feira: a conferência dos estandes, quem trabalha no evento e as credenciais
                de premiação. As seções seguem as <strong>datas do evento</strong> — fora delas, elas
                abrem só para consulta.
            </p>

            {config && !config.aberto && config.motivo_fechado && (
                <div className="max-w-3xl mb-4"><Alert type="info">{config.motivo_fechado}</Alert></div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 max-w-3xl mb-6">
                {SECOES.map((s) => (
                    <Link
                        key={s.to}
                        to={s.to}
                        className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 hover:ring-2 hover:ring-primary-container/30 transition-all"
                    >
                        <span className="material-symbols-outlined text-primary-container text-3xl">{s.icon}</span>
                        <h2 className="font-display text-lg font-semibold text-on-surface mt-2">{s.titulo}</h2>
                        <p className="text-sm text-on-surface-variant mt-1">{s.descricao}</p>
                    </Link>
                ))}
            </div>

            <div className="space-y-6">
                <Itens />
                {config && <Orientacoes inicial={config.informacoes_avaliador} />}
            </div>
        </AppShell>
    );
}
