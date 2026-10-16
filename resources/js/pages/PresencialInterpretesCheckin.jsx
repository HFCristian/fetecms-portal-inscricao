import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button, Input } from '../components/ui.jsx';
import SeletorOcorrencia from '../components/SeletorOcorrencia.jsx';
import LeitorCracha from '../components/LeitorCracha.jsx';
import { extractErrors } from '../lib/auth.jsx';
import {
    getCheckinInterpretes,
    checkinInterpretePorCodigo,
    buscarInterpretesCheckin,
    registrarChegadaInterprete,
    ocorrenciaDaChave,
} from '../lib/interpretes.js';

/** O card da porta a partir do que o servidor devolve. */
const cardDe = (dados) => ({
    tipo: dados.ja_presente ? 'repetido' : 'novo',
    nome: dados.participante.nome,
    papel_label: dados.participante.papel_label,
    projeto_titulo: dados.projeto_titulo,
    checkin_em: dados.checkin_em,
});

/**
 * Avaliação presencial → Intérpretes → **Check-in**: a porta dos intérpretes.
 *
 * Escolhe-se o dia e o turno (vem escolhido o que está acontecendo, senão o
 * próximo) e a chegada é registrada pelo crachá — leitor USB ou câmera — ou,
 * para quem chega sem ele, pela busca por nome ou CPF. A conta temporária da
 * aba (voluntário) atende esta tela.
 */
export default function PresencialInterpretesCheckin() {
    const [config, setConfig] = useState(null);
    const [chave, setChave] = useState(null);
    const [termo, setTermo] = useState('');
    const [resultados, setResultados] = useState([]);
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [registrando, setRegistrando] = useState(null);
    const timer = useRef(null);

    useEffect(() => {
        getCheckinInterpretes()
            .then((c) => { setConfig(c); setChave(c.em_foco); })
            .catch((e) => setAlerta(extractErrors(e).message || 'Não foi possível carregar o check-in.'));
    }, []);

    const ocorrencia = ocorrenciaDaChave(chave);

    function buscar(valor = termo) {
        if (!ocorrencia || valor.trim().length < 2) {
            setResultados([]);
            return;
        }
        buscarInterpretesCheckin(valor.trim(), ocorrencia).then(setResultados).catch(() => setResultados([]));
    }

    useEffect(() => {
        clearTimeout(timer.current);
        timer.current = setTimeout(() => buscar(termo), 300);
        return () => clearTimeout(timer.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [termo, chave]);

    async function registrar(i) {
        setRegistrando(i.id);
        try {
            const dados = await registrarChegadaInterprete(i.id, ocorrencia);
            setSucesso(dados.ja_presente
                ? `${i.nome} já tinha chegado às ${dados.checkin_em}.`
                : `Chegada de ${i.nome} registrada às ${dados.checkin_em}.`);
            setAlerta('');
            buscar();
        } catch (e) {
            const { message, fields } = extractErrors(e);
            setAlerta(Object.values(fields ?? {})[0] || message || 'Não foi possível registrar.');
        } finally {
            setRegistrando(null);
        }
    }

    return (
        <AppShell>
            <div className="flex items-center gap-2 text-sm text-on-surface-variant mb-2">
                <Link to="/admin/presencial" className="hover:text-primary">Avaliação presencial</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <Link to="/admin/presencial/interpretes" className="hover:text-primary">Intérpretes</Link>
                <span className="material-symbols-outlined text-[18px]">chevron_right</span>
                <span>Check-in</span>
            </div>

            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Check-in dos intérpretes</h1>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Registre a chegada de cada intérprete no dia e turno — pelo crachá, ou pelo nome para
                quem chegou sem ele.
            </p>

            {alerta && <div className="mb-4 max-w-3xl"><Alert>{alerta}</Alert></div>}
            {sucesso && <div className="mb-4 max-w-3xl"><Alert type="info">{sucesso}</Alert></div>}

            <div className="max-w-3xl space-y-4">
                <div className="max-w-md">
                    <SeletorOcorrencia config={config} chave={chave} onTrocar={(c) => { setChave(c); setSucesso(''); }} />
                </div>

                {ocorrencia && (
                    <>
                        <LeitorCracha
                            key={chave}
                            campoId="interprete-codigo"
                            rotuloBotao="Registrar chegada"
                            nota="Lido o crachá, a chegada já fica registrada no dia e turno escolhidos."
                            placeholder="Bipe o código de barras ou digite (2026-0-123-I7)"
                            registrar={async (codigo) => {
                                const dados = await checkinInterpretePorCodigo(codigo, ocorrencia);
                                return { dados, card: cardDe(dados) };
                            }}
                        />

                        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                            <h2 className="font-display font-semibold text-on-surface mb-2">Sem crachá</h2>
                            <Input
                                value={termo}
                                onChange={(e) => setTermo(e.target.value)}
                                placeholder="Buscar por nome ou CPF…"
                                aria-label="Buscar intérprete por nome ou CPF"
                            />
                            {termo.trim().length >= 2 && resultados.length === 0 && (
                                <p className="text-sm text-on-surface-variant mt-2">Nenhum intérprete encontrado.</p>
                            )}
                            {resultados.length > 0 && (
                                <ul className="mt-2 divide-y divide-outline-variant/30">
                                    {resultados.map((i) => (
                                        <li key={i.id} className="py-2 flex items-center gap-3">
                                            <span className="min-w-0 flex-1">
                                                <span className="text-sm font-medium text-on-surface block">{i.nome}</span>
                                                <span className="text-xs text-on-surface-variant block">
                                                    {i.tipos_labels.join(', ') || 'Intérprete'}
                                                    {i.presente ? ` · chegou às ${i.checkin_em}` : ''}
                                                </span>
                                            </span>
                                            {i.presente ? (
                                                <span className="text-sm font-semibold text-secondary">Presente</span>
                                            ) : (
                                                <Button
                                                    variant="outline"
                                                    aria-label={`Registrar chegada de ${i.nome}`}
                                                    loading={registrando === i.id}
                                                    onClick={() => registrar(i)}
                                                >
                                                    Registrar chegada
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </>
                )}
            </div>
        </AppShell>
    );
}
