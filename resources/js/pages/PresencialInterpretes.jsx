import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, CpfInput, DateInput, Field, Input, Select, useConfirm } from '../components/ui.jsx';
import { extractErrors, useAuth } from '../lib/auth.jsx';
import {
    getInterpretes,
    buscarFinalistasInterprete,
    criarInterprete,
    atualizarInterprete,
    excluirInterprete,
    atenderPedidoInterprete,
    exportarInterpretes,
    baixarEtiquetasInterpretes,
    descreverVinculo,
} from '../lib/interpretes.js';

const rotuloEstande = (n) => String(n).padStart(3, '0');

const SUBSECOES = [
    {
        to: '/admin/presencial/interpretes/mapa',
        icon: 'map',
        titulo: 'Mapa de designação',
        descricao: 'Escolha um intérprete e clique nos estandes em que ele atende, turno a turno.',
        organizacao: true,
    },
    {
        to: '/admin/presencial/interpretes/checkin',
        icon: 'qr_code_scanner',
        titulo: 'Check-in',
        descricao: 'A chegada de cada intérprete no dia e turno, pelo crachá ou pelo nome.',
        organizacao: false,
    },
    {
        to: '/admin/presencial/interpretes/acompanhamento',
        icon: 'monitoring',
        titulo: 'Acompanhamento',
        descricao: 'Quem devia estar no turno, quem já chegou e quem falta.',
        organizacao: true,
    },
];

/**
 * Busca de finalista para o vínculo: cada resultado oferece o projeto inteiro
 * e cada pessoa da equipe.
 */
function BuscaVinculo({ onEscolher }) {
    const [termo, setTermo] = useState('');
    const [resultados, setResultados] = useState([]);
    const [buscando, setBuscando] = useState(false);
    const timer = useRef(null);

    useEffect(() => {
        if (termo.trim().length < 2) {
            setResultados([]);
            return undefined;
        }

        setBuscando(true);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            buscarFinalistasInterprete(termo.trim())
                .then(setResultados)
                .catch(() => setResultados([]))
                .finally(() => setBuscando(false));
        }, 300);

        return () => clearTimeout(timer.current);
    }, [termo]);

    return (
        <div>
            <Input
                value={termo}
                onChange={(e) => setTermo(e.target.value)}
                placeholder="Buscar finalista por título, código ou participante…"
                aria-label="Buscar finalista para vincular"
            />
            {buscando && <p className="text-xs text-on-surface-variant mt-1">Buscando…</p>}
            {termo.trim().length >= 2 && !buscando && resultados.length === 0 && (
                <p className="text-xs text-on-surface-variant mt-1">Nenhum finalista encontrado.</p>
            )}
            {resultados.length > 0 && (
                <ul className="mt-2 border border-outline-variant/50 rounded-lg divide-y divide-outline-variant/30 max-h-64 overflow-y-auto">
                    {resultados.map((p) => (
                        <li key={p.id} className="px-3 py-2">
                            <p className="text-sm font-medium text-on-surface">
                                {p.codigo ? `${p.codigo} · ` : ''}{p.titulo}
                            </p>
                            <p className="text-xs text-on-surface-variant mb-1">
                                {[p.turno ? `Turno ${p.turno}` : null, p.numero ? `Estande ${rotuloEstande(p.numero)}` : null, p.area].filter(Boolean).join(' · ')}
                            </p>
                            <div className="flex flex-wrap gap-1">
                                <button
                                    type="button"
                                    onClick={() => onEscolher({
                                        projeto_id: p.id, pessoa_tipo: null, pessoa_id: null,
                                        projeto_titulo: p.titulo, codigo: p.codigo,
                                    })}
                                    className="px-2 py-0.5 rounded-full text-xs border border-primary-container text-primary-container hover:bg-primary-fixed"
                                >
                                    Projeto inteiro
                                </button>
                                {p.pessoas.map((pessoa) => (
                                    <button
                                        key={`${pessoa.tipo}-${pessoa.id}`}
                                        type="button"
                                        onClick={() => onEscolher({
                                            projeto_id: p.id, pessoa_tipo: pessoa.tipo, pessoa_id: pessoa.id,
                                            pessoa_nome: pessoa.nome, pessoa_papel: pessoa.papel_label,
                                            projeto_titulo: p.titulo, codigo: p.codigo,
                                        })}
                                        className="px-2 py-0.5 rounded-full text-xs border border-outline-variant text-on-surface-variant hover:bg-surface-variant"
                                    >
                                        {pessoa.nome} · {pessoa.papel_label}
                                    </button>
                                ))}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

const chaveVinculo = (v) => `${v.projeto_id}|${v.pessoa_tipo ?? ''}|${v.pessoa_tipo ? v.pessoa_id : ''}`;

/** O cadastro (novo ou edição): nome e CPF obrigatórios, o resto opcional. */
function FormInterprete({ inicial, tipos, vinculoInicial, onSalvo, onFechar }) {
    const [nome, setNome] = useState(inicial?.nome ?? '');
    const [cpf, setCpf] = useState(inicial?.cpf_digitos ?? '');
    const [nascimento, setNascimento] = useState(inicial?.data_nascimento ?? '');
    const [marcados, setMarcados] = useState(inicial?.tipos ?? []);
    const [outraLingua, setOutraLingua] = useState(inicial?.outra_lingua ?? '');
    const [outroPcd, setOutroPcd] = useState(inicial?.outro_pcd ?? '');
    const [vinculos, setVinculos] = useState(() => {
        const base = inicial?.vinculos ?? [];
        return vinculoInicial && !base.some((v) => chaveVinculo(v) === chaveVinculo(vinculoInicial))
            ? [...base, vinculoInicial]
            : base;
    });
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState('');
    const [erros, setErros] = useState({});

    function alternarTipo(valor) {
        setMarcados((m) => (m.includes(valor) ? m.filter((x) => x !== valor) : [...m, valor]));
    }

    function acrescentar(v) {
        setVinculos((lista) => (lista.some((x) => chaveVinculo(x) === chaveVinculo(v)) ? lista : [...lista, v]));
    }

    async function salvar() {
        setSalvando(true);
        setErro('');
        setErros({});
        const dados = {
            nome,
            cpf,
            data_nascimento: nascimento || null,
            tipos: marcados,
            outra_lingua: outraLingua,
            outro_pcd: outroPcd,
            vinculos: vinculos.map((v) => ({ projeto_id: v.projeto_id, pessoa_tipo: v.pessoa_tipo, pessoa_id: v.pessoa_id })),
        };

        try {
            const resp = inicial ? await atualizarInterprete(inicial.id, dados) : await criarInterprete(dados);
            onSalvo(resp);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErros(fields ?? {});
            setErro(Object.keys(fields ?? {}).length ? 'Confira os campos destacados.' : message || 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    }

    const grupos = [
        { chave: 'lingua', titulo: 'Línguas', outro: 'lingua_outra' },
        { chave: 'pcd', titulo: 'Atendimento a pessoa com deficiência', outro: 'pcd_outro' },
    ];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label={inicial ? 'Editar intérprete' : 'Novo intérprete'}>
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-2xl p-6 space-y-4 max-h-[92vh] overflow-y-auto">
                <h3 className="font-display text-xl font-semibold text-on-surface">{inicial ? 'Editar intérprete' : 'Novo intérprete'}</h3>

                {erro && <Alert>{erro}</Alert>}

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                        <Field label="Nome completo" required error={erros.nome}>
                            <Input aria-label="Nome completo" value={nome} onChange={(e) => setNome(e.target.value)} error={erros.nome} />
                        </Field>
                    </div>
                    <Field label="CPF" required error={erros.cpf}>
                        <CpfInput aria-label="CPF" value={cpf} onChange={(e) => setCpf(e.target.value)} error={erros.cpf} />
                    </Field>
                    <Field label="Data de nascimento" error={erros.data_nascimento}>
                        <DateInput aria-label="Data de nascimento" value={nascimento} onChange={(e) => setNascimento(e.target.value)} error={erros.data_nascimento} />
                    </Field>
                </div>

                <div>
                    <p className="text-sm font-semibold text-on-surface mb-1">Tipo de intérprete</p>
                    <p className="text-xs text-on-surface-variant mb-2">Opcional. Marque quantos forem.</p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {grupos.map((g) => (
                            <fieldset key={g.chave} className="rounded-lg border border-outline-variant/60 p-3">
                                <legend className="text-xs font-semibold text-primary px-1">{g.titulo}</legend>
                                <div className="space-y-1">
                                    {tipos.filter((t) => t.grupo === g.chave).map((t) => (
                                        <label key={t.value} className="flex items-center gap-2 text-sm text-on-surface">
                                            <input
                                                type="checkbox"
                                                checked={marcados.includes(t.value)}
                                                onChange={() => alternarTipo(t.value)}
                                            />
                                            {t.value === g.outro ? 'Outros' : t.label}
                                        </label>
                                    ))}
                                </div>
                                {marcados.includes(g.outro) && (
                                    <div className="mt-2">
                                        <Field error={g.chave === 'lingua' ? erros.outra_lingua : erros.outro_pcd}>
                                            <Input
                                                aria-label={g.chave === 'lingua' ? 'Qual outra língua' : 'Qual outro atendimento'}
                                                placeholder={g.chave === 'lingua' ? 'Qual língua?' : 'Qual atendimento?'}
                                                value={g.chave === 'lingua' ? outraLingua : outroPcd}
                                                onChange={(e) => (g.chave === 'lingua' ? setOutraLingua(e.target.value) : setOutroPcd(e.target.value))}
                                            />
                                        </Field>
                                    </div>
                                )}
                            </fieldset>
                        ))}
                    </div>
                </div>

                <div>
                    <p className="text-sm font-semibold text-on-surface mb-1">A quem atende</p>
                    <p className="text-xs text-on-surface-variant mb-2">
                        Opcional — dá para vincular depois, aqui ou pelo mapa. Um intérprete pode atender
                        vários projetos e várias pessoas.
                    </p>
                    {erros.vinculos && <p className="text-xs text-error mb-2">{erros.vinculos}</p>}
                    {vinculos.length > 0 && (
                        <ul className="mb-2 space-y-1">
                            {vinculos.map((v) => (
                                <li key={chaveVinculo(v)} className="flex items-center gap-2 text-sm bg-surface-container-low rounded-lg px-3 py-1.5">
                                    <span className="flex-1 min-w-0">
                                        {descreverVinculo(v)}
                                        {v.origem === 'mapa' && <span className="text-xs text-primary-container"> · pelo mapa</span>}
                                        {v.origem === 'pedido' && <span className="text-xs text-primary-container"> · pelo pedido de suporte</span>}
                                    </span>
                                    <button
                                        type="button"
                                        aria-label={`Remover vínculo ${descreverVinculo(v)}`}
                                        onClick={() => setVinculos((lista) => lista.filter((x) => chaveVinculo(x) !== chaveVinculo(v)))}
                                        className="text-on-surface-variant hover:text-error"
                                    >
                                        <span className="material-symbols-outlined text-[18px]">close</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <BuscaVinculo onEscolher={acrescentar} />
                </div>

                <div className="flex justify-end gap-3 pt-1">
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button type="button" onClick={salvar} loading={salvando}>Salvar</Button>
                </div>
            </div>
        </div>
    );
}

/** Um pedido de intérprete da aba Suporte no evento que ainda espera alguém. */
function Pedido({ pedido, interpretes, onVincular, onCadastrar }) {
    const [escolhido, setEscolhido] = useState('');

    return (
        <li className="py-2 flex flex-wrap items-center gap-3">
            <span className="min-w-0 flex-1">
                <span className="text-sm font-medium text-on-surface block">
                    {pedido.codigo ? `${pedido.codigo} · ` : ''}{pedido.projeto_titulo}
                </span>
                <span className="text-xs text-on-surface-variant block">
                    {[pedido.tipo_label, pedido.idioma, pedido.aluno ? `para ${pedido.aluno}` : 'para o projeto',
                        pedido.numero ? `Turno ${pedido.turno} · Estande ${rotuloEstande(pedido.numero)}` : null].filter(Boolean).join(' · ')}
                </span>
            </span>
            {interpretes.length > 0 && (
                <span className="flex items-center gap-2">
                    <Select aria-label={`Intérprete para ${pedido.projeto_titulo}`} value={escolhido} onChange={(e) => setEscolhido(e.target.value)}>
                        <option value="">Escolha o intérprete…</option>
                        {interpretes.map((i) => <option key={i.id} value={i.id}>{i.nome}</option>)}
                    </Select>
                    <Button variant="outline" disabled={!escolhido} onClick={() => onVincular(Number(escolhido), pedido)}>Vincular</Button>
                </span>
            )}
            <Button variant="outline" onClick={() => onCadastrar(pedido)}>Cadastrar novo</Button>
        </li>
    );
}

/**
 * Avaliação presencial → **Intérpretes** (Sprints 214–215).
 *
 * O cadastro de quem interpreta no evento — só nome e CPF são obrigatórios —
 * e a quem cada um atende: o projeto inteiro ou alguém da equipe, em quantos
 * projetos for preciso. Daqui saem o mapa de designação, a porta (check-in) e
 * o acompanhamento, e a lista com os códigos e as etiquetas para imprimir.
 *
 * A conta temporária da aba (voluntário) vê só o atalho do check-in: o
 * cadastro é dado pessoal, e o servidor recusa o resto.
 */
export default function PresencialInterpretes() {
    const { user } = useAuth();
    const temporaria = Boolean(user?.conta_temporaria);
    const [dados, setDados] = useState(null);
    const [busca, setBusca] = useState('');
    const [form, setForm] = useState(null);   // { inicial, vinculoInicial }
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [confirm, confirmDialog] = useConfirm();

    const carregar = () => getInterpretes().then(setDados).catch((e) => setAlerta(extractErrors(e).message || 'Não foi possível carregar os intérpretes.'));

    useEffect(() => {
        if (!temporaria) carregar();
    }, [temporaria]);

    async function excluir(i) {
        const ok = await confirm({
            title: 'Excluir intérprete',
            message: `${i.nome} sai do cadastro, com os vínculos e as chegadas registradas. O crachá impresso deixa de valer.`,
            confirmLabel: 'Excluir',
            danger: true,
        });
        if (!ok) return;

        try {
            const r = await excluirInterprete(i.id);
            setSucesso(r.meta?.message ?? 'Intérprete excluído.');
            setAlerta('');
            carregar();
        } catch (e) {
            setAlerta(extractErrors(e).message || 'Não foi possível excluir.');
        }
    }

    async function vincularPedido(interpreteId, pedido) {
        try {
            const r = await atenderPedidoInterprete(interpreteId, pedido.id);
            setDados(r.data);
            setSucesso(r.meta?.message ?? 'Intérprete vinculado.');
            setAlerta('');
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível vincular.');
        }
    }

    async function baixar(acao) {
        try {
            await acao();
        } catch (e) {
            setAlerta(extractErrors(e).message || 'Não foi possível gerar o arquivo.');
        }
    }

    const termo = busca.trim().toLowerCase();
    const interpretes = (dados?.interpretes ?? []).filter((i) =>
        !termo || `${i.nome} ${i.cpf} ${i.tipos_labels.join(' ')}`.toLowerCase().includes(termo));

    return (
        <AppShell>
            <div className="flex items-center gap-2 text-sm text-on-surface-variant mb-2">
                <Link to="/admin/presencial" className="hover:text-primary">Avaliação presencial</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <span>Intérpretes</span>
            </div>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Intérpretes</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Quem interpreta no evento — línguas estrangeiras e atendimento a pessoa com
                deficiência — e a quem cada um atende: o projeto inteiro ou alguém da equipe. O
                intérprete acompanha o projeto: se ele mudar de estande ou de turno, a designação vai
                junto.
            </p>

            {alerta && <div className="mb-4 max-w-4xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-4xl"><Alert type="info">{sucesso}</Alert></div>}

            <div className="grid gap-4 sm:grid-cols-3 max-w-4xl mb-6">
                {SUBSECOES.filter((s) => !temporaria || !s.organizacao).map((s) => (
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

            {!temporaria && dados && (
                <div className="space-y-6 max-w-5xl">
                    {dados.pedidos.length > 0 && (
                        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 border-l-4 border-primary-container">
                            <h2 className="font-display text-lg font-semibold text-on-surface mb-1">
                                Pedidos de intérprete sem atendimento ({dados.pedidos.length})
                            </h2>
                            <p className="text-xs text-on-surface-variant mb-2">
                                Pedidos aprovados na aba Suporte no evento cujo projeto ainda não tem intérprete.
                            </p>
                            <ul className="divide-y divide-outline-variant/30">
                                {dados.pedidos.map((p) => (
                                    <Pedido
                                        key={p.id}
                                        pedido={p}
                                        interpretes={dados.interpretes}
                                        onVincular={vincularPedido}
                                        onCadastrar={(pedido) => setForm({
                                            inicial: null,
                                            vinculoInicial: {
                                                projeto_id: pedido.projeto_id,
                                                pessoa_tipo: null,
                                                pessoa_id: null,
                                                projeto_titulo: pedido.projeto_titulo,
                                                codigo: pedido.codigo,
                                            },
                                        })}
                                    />
                                ))}
                            </ul>
                        </section>
                    )}

                    <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                        <div className="flex flex-wrap items-center gap-3 mb-4">
                            <h2 className="font-display text-lg font-semibold text-on-surface mr-auto">
                                Cadastro — {dados.interpretes.length} intérprete(s)
                            </h2>
                            <Button onClick={() => setForm({ inicial: null, vinculoInicial: null })}>
                                <span className="material-symbols-outlined text-[20px]">person_add</span>
                                Novo intérprete
                            </Button>
                        </div>

                        {dados.interpretes.length > 0 && (
                            <div className="mb-3 max-w-sm">
                                <Input
                                    value={busca}
                                    onChange={(e) => setBusca(e.target.value)}
                                    placeholder="Buscar por nome, CPF ou tipo…"
                                    aria-label="Buscar intérprete"
                                />
                            </div>
                        )}

                        {dados.interpretes.length === 0 ? (
                            <p className="text-sm text-on-surface-variant">Nenhum intérprete cadastrado ainda.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-xs uppercase tracking-wide text-on-surface-variant border-b border-outline-variant/40">
                                            <th className="py-2 pr-3">Nome</th>
                                            <th className="py-2 pr-3">Tipos</th>
                                            <th className="py-2 pr-3">Atende</th>
                                            <th className="py-2 pr-3">Código</th>
                                            <th className="py-2" />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-outline-variant/30">
                                        {interpretes.map((i) => (
                                            <tr key={i.id} className="align-top">
                                                <td className="py-2 pr-3">
                                                    <span className="font-medium text-on-surface block">{i.nome}</span>
                                                    <span className="text-xs text-on-surface-variant">
                                                        CPF {i.cpf}{i.data_nascimento_label ? ` · nasc. ${i.data_nascimento_label}` : ''}
                                                    </span>
                                                </td>
                                                <td className="py-2 pr-3 text-on-surface-variant">{i.tipos_labels.join(', ') || '—'}</td>
                                                <td className="py-2 pr-3">
                                                    {i.vinculos.length === 0 ? (
                                                        <span className="text-on-surface-variant">Sem vínculo</span>
                                                    ) : (
                                                        <>
                                                            <ul className="text-xs text-on-surface space-y-0.5">
                                                                {i.vinculos.map((v) => (
                                                                    <li key={v.id} className={v.na_lista ? '' : 'text-error'}>
                                                                        {descreverVinculo(v)}{v.na_lista ? '' : ' — fora da lista final'}
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                            {i.locais_label && (
                                                                <span className="text-xs text-primary-container block mt-1">{i.locais_label}</span>
                                                            )}
                                                        </>
                                                    )}
                                                </td>
                                                <td className="py-2 pr-3 font-mono text-xs text-on-surface-variant whitespace-nowrap">{i.codigo}</td>
                                                <td className="py-2 whitespace-nowrap text-right">
                                                    <button
                                                        type="button"
                                                        aria-label={`Editar ${i.nome}`}
                                                        onClick={() => setForm({ inicial: i, vinculoInicial: null })}
                                                        className="w-8 h-8 rounded-lg border border-outline-variant/60 text-on-surface-variant hover:text-primary inline-flex items-center justify-center mr-1"
                                                    >
                                                        <span className="material-symbols-outlined text-[18px]">edit</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        aria-label={`Etiqueta de ${i.nome}`}
                                                        onClick={() => baixar(() => baixarEtiquetasInterpretes([i.id]))}
                                                        className="w-8 h-8 rounded-lg border border-outline-variant/60 text-on-surface-variant hover:text-primary inline-flex items-center justify-center mr-1"
                                                    >
                                                        <span className="material-symbols-outlined text-[18px]">badge</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        aria-label={`Excluir ${i.nome}`}
                                                        onClick={() => excluir(i)}
                                                        className="w-8 h-8 rounded-lg border border-outline-variant/60 text-on-surface-variant hover:text-error inline-flex items-center justify-center"
                                                    >
                                                        <span className="material-symbols-outlined text-[18px]">delete</span>
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                        <h2 className="font-display text-lg font-semibold text-on-surface mb-1">Exportar</h2>
                        <p className="text-sm text-on-surface-variant mb-3">
                            A lista de intérpretes com o código do crachá de cada um, e as etiquetas de
                            identificação no mesmo modelo das dos participantes (16 por folha, QR e código de
                            barras).
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" disabled={dados.interpretes.length === 0} onClick={() => baixar(() => exportarInterpretes('xlsx'))}>Excel</Button>
                            <Button variant="outline" disabled={dados.interpretes.length === 0} onClick={() => baixar(() => exportarInterpretes('csv'))}>CSV</Button>
                            <Button variant="outline" disabled={dados.interpretes.length === 0} onClick={() => baixar(() => baixarEtiquetasInterpretes())}>
                                <span className="material-symbols-outlined text-[20px]">badge</span>
                                Etiquetas (PDF)
                            </Button>
                        </div>
                    </section>
                </div>
            )}

            {form && dados && (
                <FormInterprete
                    inicial={form.inicial}
                    vinculoInicial={form.vinculoInicial}
                    tipos={dados.tipos}
                    onSalvo={(r) => {
                        setForm(null);
                        setSucesso(r.meta?.message ?? 'Intérprete salvo.');
                        setAlerta('');
                        carregar();
                    }}
                    onFechar={() => setForm(null)}
                />
            )}

            {confirmDialog}
        </AppShell>
    );
}
