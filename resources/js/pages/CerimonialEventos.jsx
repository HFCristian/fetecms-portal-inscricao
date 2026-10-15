import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    atualizarEventoCerimonia, criarEventoCerimonia, excluirEventoCerimonia, getEventosCerimonia,
} from '../lib/cerimonial.js';

/** As categorias que formam o público, na ordem da lista final. */
export const CATEGORIAS = [
    { valor: 'fetecms', label: 'FETECMS' },
    { valor: 'fetec_jr', label: 'FETEC Jr' },
    { valor: 'fetecms_fundect', label: 'FETECMS FUNDECT' },
];

const VAZIO = { id: null, nome: '', categorias: [] };

/** O formulário de um evento: o nome e as categorias do público. */
function FormularioEvento({ inicial, onSalvo, onCancelar }) {
    const [dados, setDados] = useState(inicial);
    const [erros, setErros] = useState({});
    const [erro, setErro] = useState('');
    const [salvando, setSalvando] = useState(false);

    function alternar(valor) {
        setDados((d) => ({
            ...d,
            categorias: d.categorias.includes(valor)
                ? d.categorias.filter((c) => c !== valor)
                : [...d.categorias, valor],
        }));
    }

    async function salvar(e) {
        e.preventDefault();
        setSalvando(true); setErros({}); setErro('');
        try {
            const corpo = { nome: dados.nome, categorias: dados.categorias };
            const salvo = dados.id
                ? await atualizarEventoCerimonia(dados.id, corpo)
                : await criarEventoCerimonia(corpo);
            onSalvo(salvo);
        } catch (err) {
            const { message, fields } = extractErrors(err);
            setErros(fields ?? {});
            if (!fields || Object.keys(fields).length === 0) setErro(message || 'Não foi possível salvar o evento.');
        } finally {
            setSalvando(false);
        }
    }

    const erroCategorias = erros.categorias ?? Object.entries(erros).find(([k]) => k.startsWith('categorias.'))?.[1];

    return (
        <form onSubmit={salvar} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 space-y-4">
            <h2 className="font-display text-lg font-semibold text-on-surface">
                {dados.id ? 'Editar evento' : 'Novo evento de cerimônia'}
            </h2>

            <label className="block">
                <span className="text-sm font-semibold text-on-surface">Nome do evento</span>
                <input
                    value={dados.nome}
                    onChange={(e) => setDados((d) => ({ ...d, nome: e.target.value }))}
                    maxLength={120}
                    placeholder="Ex.: Premiação FETECMS"
                    aria-label="Nome do evento"
                    className="w-full mt-1 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                />
                {erros.nome && <span className="text-xs text-error mt-1 block">{erros.nome}</span>}
            </label>

            <fieldset>
                <legend className="text-sm font-semibold text-on-surface">Público</legend>
                <p className="text-xs text-on-surface-variant mb-2">
                    Os finalistas destas categorias fazem check-in neste evento. Uma categoria pode
                    estar em mais de um evento.
                </p>
                <div className="flex flex-wrap gap-4">
                    {CATEGORIAS.map((c) => (
                        <label key={c.valor} className="flex items-center gap-2 text-sm text-on-surface cursor-pointer select-none">
                            <input
                                type="checkbox"
                                className="w-4 h-4 accent-[color:var(--color-primary-container,#43157a)]"
                                checked={dados.categorias.includes(c.valor)}
                                onChange={() => alternar(c.valor)}
                            />
                            {c.label}
                        </label>
                    ))}
                </div>
                {erroCategorias && <span className="text-xs text-error mt-1 block">{erroCategorias}</span>}
            </fieldset>

            {erro && <Alert>{erro}</Alert>}

            <div className="flex flex-wrap gap-2">
                <Button type="submit" loading={salvando}>{dados.id ? 'Salvar' : 'Criar evento'}</Button>
                {onCancelar && <Button type="button" variant="outline" onClick={onCancelar}>Cancelar</Button>}
            </div>
        </form>
    );
}

/**
 * Cerimonial → **Eventos de cerimônia**.
 *
 * A feira faz mais de uma premiação com o mesmo funcionamento e públicos
 * diferentes — uma para a FETECMS, outra para a FETEC Jr e a FETECMS FUNDECT.
 * Aqui a organização dá nome a cada uma e diz que categorias ela recebe; na
 * porta, quem faz o check-in escolhe o evento em que está.
 *
 * Evento que já recebeu alguém não é excluído (ele é a memória de quem esteve
 * na sala); para mudar quem ele recebe, edita-se o público.
 */
export default function CerimonialEventos() {
    const [eventos, setEventos] = useState(null);
    const [editando, setEditando] = useState(null);
    const [erro, setErro] = useState('');
    const [aviso, setAviso] = useState('');
    const [confirm, dialogo] = useConfirm();

    useEffect(() => {
        getEventosCerimonia()
            .then(setEventos)
            .catch((e) => {
                const { message } = extractErrors(e);
                setErro(message || 'Não foi possível carregar os eventos.');
                setEventos([]);
            });
    }, []);

    function salvo(evento) {
        setEventos((lista) => {
            const existe = (lista ?? []).some((e) => e.id === evento.id);
            return existe ? lista.map((e) => (e.id === evento.id ? evento : e)) : [...(lista ?? []), evento];
        });
        setAviso(editando?.id ? `Evento "${evento.nome}" atualizado.` : `Evento "${evento.nome}" criado.`);
        setEditando(null);
    }

    async function excluir(evento) {
        const ok = await confirm({
            title: 'Excluir evento',
            message: `"${evento.nome}" deixa de aparecer na porta e no painel.`,
            confirmLabel: 'Excluir',
            danger: true,
        });
        if (!ok) return;

        setErro(''); setAviso('');
        try {
            setEventos(await excluirEventoCerimonia(evento.id));
            setAviso(`Evento "${evento.nome}" excluído.`);
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setErro(Object.values(fields ?? {})[0] || message || 'Não foi possível excluir o evento.');
        }
    }

    return (
        <AppShell>
            <Link to="/admin/cerimonial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Cerimonial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Eventos de cerimônia</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Cada premiação é um evento, com o seu <strong>público</strong>: as categorias dos
                finalistas que ela recebe. Na porta, quem faz o check-in escolhe o evento; a Visão
                Geral e os premiados mostram cada um — ou todos somados.
            </p>

            <div className="max-w-3xl space-y-4">
                {erro && <Alert>{erro}</Alert>}
                {aviso && <Alert type="info">{aviso}</Alert>}

                {eventos === null ? (
                    <p className="text-sm text-on-surface-variant">Carregando…</p>
                ) : eventos.length === 0 && !editando ? (
                    <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-sm text-on-surface-variant">
                        Nenhum evento cadastrado ainda. Sem um evento, a porta não registra check-in.
                    </div>
                ) : (
                    <ul className="space-y-2">
                        {eventos.map((e) => (
                            <li key={e.id} className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 flex flex-wrap items-center gap-3">
                                <span className="material-symbols-outlined text-[28px] text-primary-container" aria-hidden="true">event</span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-on-surface">{e.nome}</p>
                                    <p className="text-xs text-on-surface-variant">
                                        Público: {e.publico} · {e.checkins} check-in(s)
                                    </p>
                                </div>
                                <Button type="button" variant="outline" onClick={() => { setAviso(''); setEditando(e); }}>
                                    Editar
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={e.checkins > 0}
                                    title={e.checkins > 0 ? 'Evento com check-ins não é excluído — edite o público.' : undefined}
                                    onClick={() => excluir(e)}
                                >
                                    Excluir
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                {editando ? (
                    <FormularioEvento
                        key={editando.id ?? 'novo'}
                        inicial={editando}
                        onSalvo={salvo}
                        onCancelar={() => setEditando(null)}
                    />
                ) : (
                    eventos !== null && (
                        <Button type="button" onClick={() => { setAviso(''); setEditando(VAZIO); }}>
                            <span className="material-symbols-outlined text-[20px]">add</span>
                            Novo evento
                        </Button>
                    )
                )}
            </div>
            {dialogo}
        </AppShell>
    );
}
