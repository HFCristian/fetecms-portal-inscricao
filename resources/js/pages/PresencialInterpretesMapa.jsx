import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Input } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getMapaInterpretes, designarInterprete, descreverVinculo } from '../lib/interpretes.js';

/** Tamanho de uma célula da grade, em unidades do viewBox (o mesmo da planta). */
const CELULA = 10;
const MARGEM = 6;

const TURNOS = [
    { value: 'A', label: 'Turno A (matutino)' },
    { value: 'B', label: 'Turno B (vespertino)' },
];

const rotulo = (n) => String(n).padStart(3, '0');

const CORES = {
    mapa: { fill: '#43157A', stroke: '#2a0058', texto: '#ffffff' },
    cadastro: { fill: '#d4f0dc', stroke: '#006e1f', texto: '#004d15' },
    ocupado: { fill: '#ffffff', stroke: '#cfc6db', texto: '#2a0058' },
    vazio: { fill: '#f3f1f6', stroke: '#e6e0ee', texto: '#9a93a6' },
};

/**
 * Avaliação presencial → Intérpretes → **Mapa de designação**.
 *
 * Escolhe-se um intérprete à esquerda e o turno em cima; clicar num estande o
 * designa para o projeto que apresenta ali naquele turno — e clicar de novo
 * tira. A designação **é o vínculo ao projeto**: se o projeto mudar de estande
 * ou de turno, o intérprete vai junto. O estande do projeto vinculado pelo
 * cadastro (inteiro ou a alguém da equipe) aparece em verde e se muda no
 * cadastro, não aqui.
 *
 * Sem intérprete escolhido, o mapa mostra quantos intérpretes cada estande tem
 * no turno — a visão de cobertura.
 */
export default function PresencialInterpretesMapa() {
    const [dados, setDados] = useState(null);
    const [selecionado, setSelecionado] = useState(null);
    const [turno, setTurno] = useState('A');
    const [busca, setBusca] = useState('');
    const [alerta, setAlerta] = useState('');
    const [aviso, setAviso] = useState('');
    const [ocupado, setOcupado] = useState(false);

    useEffect(() => {
        getMapaInterpretes()
            .then(setDados)
            .catch((e) => setAlerta(extractErrors(e).message || 'Não foi possível carregar o mapa.'));
    }, []);

    const estandes = dados?.layout?.estandes ?? [];
    const ocupacao = dados?.ocupacao ?? {};
    const interpretes = dados?.interpretes ?? [];
    const interprete = interpretes.find((i) => i.id === selecionado) ?? null;

    const caixa = useMemo(() => (estandes.length === 0
        ? { largura: 10, altura: 10 }
        : { largura: Math.max(...estandes.map((e) => e.x)) + 1, altura: Math.max(...estandes.map((e) => e.y)) + 1 }), [estandes]);

    /** Quantos intérpretes atendem cada projeto (por qualquer vínculo). */
    const porProjeto = useMemo(() => {
        const contagem = {};
        interpretes.forEach((i) => {
            new Set(i.vinculos.map((v) => v.projeto_id)).forEach((id) => { contagem[id] = (contagem[id] ?? 0) + 1; });
        });
        return contagem;
    }, [interpretes]);

    /** O estado de um projeto para o intérprete escolhido: pelo mapa, pelo cadastro ou nada. */
    function estadoDo(projetoId) {
        if (!interprete || !projetoId) return null;
        const doProjeto = interprete.vinculos.filter((v) => v.projeto_id === projetoId);
        if (doProjeto.some((v) => !v.pessoa_tipo && v.origem === 'mapa')) return 'mapa';
        if (doProjeto.length > 0) return 'cadastro';
        return null;
    }

    async function clicar(numero) {
        setAviso('');
        const projeto = ocupacao[numero]?.[turno] ?? null;

        if (!interprete) {
            setAviso('Escolha um intérprete na lista para designá-lo nos estandes.');
            return;
        }

        if (!projeto) {
            setAviso(`O estande ${rotulo(numero)} está vazio no ${turno === 'A' ? 'turno A' : 'turno B'}: a designação é ao projeto que apresenta ali.`);
            return;
        }

        const estado = estadoDo(projeto.projeto_id);

        if (estado === 'cadastro') {
            setAviso(`${interprete.nome} já atende “${projeto.titulo}” pelo cadastro. Para tirar, edite o intérprete.`);
            return;
        }

        setOcupado(true);
        try {
            const linha = await designarInterprete(interprete.id, { numero, turno, designado: estado !== 'mapa' });
            setDados((d) => ({ ...d, interpretes: d.interpretes.map((i) => (i.id === linha.id ? linha : i)) }));
            setAlerta('');
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível designar.');
        } finally {
            setOcupado(false);
        }
    }

    const termo = busca.trim().toLowerCase();
    const listados = interpretes.filter((i) => !termo || `${i.nome} ${i.tipos_labels.join(' ')}`.toLowerCase().includes(termo));

    return (
        <AppShell>
            <div className="flex items-center gap-2 text-sm text-on-surface-variant mb-2">
                <Link to="/admin/presencial" className="hover:text-primary">Avaliação presencial</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <Link to="/admin/presencial/interpretes" className="hover:text-primary">Intérpretes</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <span>Mapa de designação</span>
            </div>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Mapa de designação</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Escolha um intérprete e clique nos estandes em que ele atende; clicar de novo tira. A
                designação é ao <strong>projeto</strong> que apresenta no estande naquele turno — se o
                projeto mudar de lugar, o intérprete vai junto.
            </p>

            {alerta && <div className="mb-4 max-w-4xl"><Alert>{alerta}</Alert></div>}
            {aviso && <div className="mb-4 max-w-4xl"><Alert type="info">{aviso}</Alert></div>}
            {dados && !dados.estandes_gerados && (
                <div className="mb-4 max-w-4xl">
                    <Alert type="warning">
                        Os estandes ainda não foram distribuídos (Mapa do Evento → Estandes dos Projetos):
                        sem projeto nos estandes, não há o que designar. Os vínculos podem ser feitos no cadastro.
                    </Alert>
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-[18rem_1fr]">
                <aside className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 self-start">
                    <h2 className="font-display font-semibold text-on-surface mb-2">Intérpretes</h2>
                    <Input value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Buscar…" aria-label="Buscar intérprete no mapa" />
                    <ul className="mt-2 space-y-1 max-h-[28rem] overflow-y-auto" role="listbox" aria-label="Escolha o intérprete">
                        {listados.map((i) => (
                            <li key={i.id}>
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={i.id === selecionado}
                                    onClick={() => { setSelecionado(i.id === selecionado ? null : i.id); setAviso(''); }}
                                    className={`w-full text-left rounded-lg px-3 py-2 text-sm ${
                                        i.id === selecionado ? 'bg-primary-container text-on-primary' : 'hover:bg-surface-variant text-on-surface'
                                    }`}
                                >
                                    <span className="font-medium block">{i.nome}</span>
                                    <span className={`text-xs block ${i.id === selecionado ? 'text-on-primary/80' : 'text-on-surface-variant'}`}>
                                        {i.tipos_labels.join(', ') || 'Sem tipo informado'}
                                    </span>
                                </button>
                            </li>
                        ))}
                        {interpretes.length === 0 && dados && (
                            <li className="text-sm text-on-surface-variant">Nenhum intérprete cadastrado.</li>
                        )}
                    </ul>

                    {interprete && (
                        <div className="mt-4 border-t border-outline-variant/40 pt-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-on-surface-variant mb-1">Atende</p>
                            {interprete.vinculos.length === 0 ? (
                                <p className="text-sm text-on-surface-variant">Nenhum projeto ainda.</p>
                            ) : (
                                <ul className="text-xs text-on-surface space-y-1">
                                    {interprete.vinculos.map((v) => (
                                        <li key={v.id}>
                                            {descreverVinculo(v)}
                                            {v.turno && (
                                                <span className="text-on-surface-variant">
                                                    {' '}· Turno {v.turno}{v.numero ? ` · Estande ${rotulo(v.numero)}` : ''}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </aside>

                <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4 min-w-0">
                    <div className="flex flex-wrap items-center gap-2 mb-3" role="radiogroup" aria-label="Turno do mapa">
                        {TURNOS.map((t) => (
                            <button
                                key={t.value}
                                type="button"
                                role="radio"
                                aria-checked={turno === t.value}
                                onClick={() => setTurno(t.value)}
                                className={`px-3 py-1 rounded-full text-sm font-semibold border ${
                                    turno === t.value
                                        ? 'bg-primary-container text-on-primary border-primary-container'
                                        : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                        <span className="text-sm text-on-surface-variant ml-auto">
                            {interprete ? `Designando: ${interprete.nome}` : 'Escolha um intérprete para designar'}
                            {ocupado ? ' · salvando…' : ''}
                        </span>
                    </div>

                    <div className="overflow-x-auto">
                        <svg
                            role="img"
                            aria-label="Planta do evento para designar intérpretes"
                            viewBox={`0 0 ${caixa.largura * CELULA + MARGEM * 2} ${caixa.altura * CELULA + MARGEM * 2}`}
                            className="w-full h-auto min-w-[36rem]"
                        >
                            {estandes.map((e) => {
                                const projeto = ocupacao[e.numero]?.[turno] ?? null;
                                const estado = estadoDo(projeto?.projeto_id);
                                const cores = estado ? CORES[estado] : projeto ? CORES.ocupado : CORES.vazio;
                                const quantos = projeto ? porProjeto[projeto.projeto_id] ?? 0 : 0;
                                const x0 = MARGEM + e.x * CELULA;
                                const y0 = MARGEM + e.y * CELULA;

                                return (
                                    <g
                                        key={e.numero}
                                        role="button"
                                        tabIndex={0}
                                        aria-label={`Estande ${rotulo(e.numero)}${projeto ? ` · ${projeto.titulo}` : ' · vazio'}${estado === 'mapa' ? ' · designado' : ''}`}
                                        aria-pressed={estado === 'mapa'}
                                        style={{ cursor: 'pointer' }}
                                        onClick={() => clicar(e.numero)}
                                        onKeyDown={(ev) => { if (ev.key === 'Enter') clicar(e.numero); }}
                                    >
                                        <title>{projeto ? `${rotulo(e.numero)} · ${projeto.titulo}` : `${rotulo(e.numero)} · vazio neste turno`}</title>
                                        <rect
                                            x={x0}
                                            y={y0}
                                            width={CELULA - 1}
                                            height={CELULA - 1}
                                            rx="1.5"
                                            fill={cores.fill}
                                            stroke={cores.stroke}
                                            strokeWidth="0.6"
                                            strokeDasharray={projeto ? undefined : '1.5 1'}
                                        />
                                        <text
                                            x={x0 + (CELULA - 1) / 2}
                                            y={y0 + (CELULA - 1) / 2 + 1.6}
                                            textAnchor="middle"
                                            fontSize="3.6"
                                            fontWeight="600"
                                            fill={cores.texto}
                                        >
                                            {rotulo(e.numero)}
                                        </text>
                                        {quantos > 0 && (
                                            <>
                                                <circle cx={x0 + CELULA - 2.2} cy={y0 + 1.6} r="1.6" fill="#006e1f" />
                                                <text x={x0 + CELULA - 2.2} y={y0 + 2.3} textAnchor="middle" fontSize="2" fontWeight="700" fill="#ffffff">
                                                    {quantos}
                                                </text>
                                            </>
                                        )}
                                    </g>
                                );
                            })}
                        </svg>
                    </div>

                    <div className="flex flex-wrap gap-4 mt-3 text-xs text-on-surface-variant">
                        <span className="inline-flex items-center gap-1">
                            <span className="w-3 h-3 rounded-sm border" style={{ background: CORES.mapa.fill, borderColor: CORES.mapa.stroke }} />
                            Designado pelo mapa
                        </span>
                        <span className="inline-flex items-center gap-1">
                            <span className="w-3 h-3 rounded-sm border" style={{ background: CORES.cadastro.fill, borderColor: CORES.cadastro.stroke }} />
                            Vinculado no cadastro
                        </span>
                        <span className="inline-flex items-center gap-1">
                            <span className="w-3 h-3 rounded-sm border" style={{ background: CORES.vazio.fill, borderColor: CORES.vazio.stroke }} />
                            Vazio no turno
                        </span>
                        <span className="inline-flex items-center gap-1">
                            <span className="w-3 h-3 rounded-full" style={{ background: '#006e1f' }} />
                            Intérpretes no estande
                        </span>
                    </div>
                </section>
            </div>
        </AppShell>
    );
}
