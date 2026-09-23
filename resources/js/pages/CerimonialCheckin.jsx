import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import LeitorCerimonial from '../components/LeitorCerimonial.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { buscarParticipantes, getCerimonialConfig } from '../lib/cerimonial.js';
import { useModoTeste } from '../lib/modoTeste.js';

/**
 * Cerimonial → **Check-in**.
 *
 * Dois caminhos para a mesma ficha, porque a fila da cerimônia tem os dois
 * casos: quem chega com o crachá na mão (leitor ou câmera) e quem chega sem
 * ele — o crachá ficou no estande, a etiqueta rasgou. Para esse segundo caso a
 * busca aceita **nome ou CPF**.
 *
 * Os dois caminhos terminam na **ficha do projeto**, e não num check-in
 * imediato: a equipe chega junta, e marcar a pessoa encontrada e mais três
 * colegas de uma vez é o que faz a fila andar.
 */
export default function CerimonialCheckin() {
    const [teste] = useModoTeste();
    const navigate = useNavigate();
    const [config, setConfig] = useState(null);
    const [termo, setTermo] = useState('');
    const [resultados, setResultados] = useState(null);
    const [buscando, setBuscando] = useState(false);
    const [erro, setErro] = useState('');
    const debounce = useRef(null);

    useEffect(() => {
        getCerimonialConfig(teste).then(setConfig).catch(() => setConfig(null));
    }, [teste]);

    // A busca acompanha a digitação, mas espera a mão parar: numa fila, quem
    // digita "ana carolina" não quer doze consultas pelo caminho.
    useEffect(() => {
        if (debounce.current) clearTimeout(debounce.current);

        if (termo.trim().length < 2) {
            setResultados(null);
            return undefined;
        }

        debounce.current = setTimeout(() => {
            setBuscando(true);
            setErro('');
            buscarParticipantes(termo.trim(), teste)
                .then(setResultados)
                .catch((e) => {
                    const { message } = extractErrors(e);
                    setErro(message || 'Não foi possível buscar agora.');
                    setResultados([]);
                })
                .finally(() => setBuscando(false));
        }, 350);

        return () => clearTimeout(debounce.current);
    }, [termo, teste]);

    const abrir = (projetoId) => navigate(`/admin/cerimonial/projetos/${projetoId}`);

    return (
        <AppShell>
            <Link to="/admin/cerimonial" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Cerimonial
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Check-in</h1>
            <p className="text-on-surface-variant mb-6 max-w-3xl">
                Leia o crachá do participante ou procure pelo <strong>nome ou CPF</strong>. Em
                qualquer dos casos abre a <strong>ficha do projeto</strong>, onde você marca de uma
                vez todo mundo que chegou junto.
            </p>

            {config?.lista?.demo && (
                <div className="mb-4 max-w-3xl">
                    <Alert type="info">
                        <strong>Modo de teste</strong> ligado: você está atendendo a lista de
                        demonstração, e estes check-ins não contam na cerimônia de verdade.
                    </Alert>
                </div>
            )}

            {config && !config.aberto && (
                <div className="mb-4 max-w-3xl">
                    <Alert>
                        {config.encerrado
                            ? `O evento foi encerrado em ${config.fim_label} — a tela abre só para consulta.`
                            : config.inicio_label
                                ? `O cerimonial abre em ${config.inicio_label} — até lá a tela é só consulta.`
                                : 'O período do evento ainda não foi definido, então o check-in está fechado.'}
                    </Alert>
                </div>
            )}

            {config && !config.lista ? (
                <div className="max-w-3xl">
                    <Alert>
                        Nenhuma lista final {config.modo_teste ? 'de demonstração' : 'oficial'} está
                        vigente nesta edição — sem ela não há finalistas para receber.
                    </Alert>
                </div>
            ) : (
                <div className="max-w-3xl">
                    <LeitorCerimonial teste={teste} onAbrir={(d) => abrir(d.projeto_id)} />

                    <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-4">
                        <label className="text-sm font-semibold text-on-surface" htmlFor="cerimonial-busca">
                            Procurar por nome ou CPF
                        </label>
                        <input
                            id="cerimonial-busca"
                            value={termo}
                            onChange={(e) => setTermo(e.target.value)}
                            placeholder="Nome do aluno, do orientador… ou o CPF"
                            aria-label="Procurar por nome ou CPF"
                            className="w-full mt-1 bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                        />

                        {erro && <div className="mt-3"><Alert>{erro}</Alert></div>}

                        {buscando && (
                            <p className="text-xs text-on-surface-variant mt-3">Procurando…</p>
                        )}

                        {!buscando && resultados !== null && resultados.length === 0 && (
                            <p className="text-sm text-on-surface-variant mt-3">
                                Ninguém encontrado entre os finalistas. Confira a grafia — ou o CPF,
                                que também serve.
                            </p>
                        )}

                        {!buscando && resultados !== null && resultados.length > 0 && (
                            <ul className="mt-3 divide-y divide-outline-variant/30">
                                {resultados.map((p) => (
                                    <li key={`${p.projeto_id}-${p.chave}`} className="py-2 flex flex-wrap items-center gap-2">
                                        <span
                                            className={`material-symbols-outlined text-[28px] shrink-0 ${
                                                p.presente ? 'text-green-700' : 'text-on-surface-variant/60'
                                            }`}
                                            aria-hidden="true"
                                        >
                                            account_circle
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm text-on-surface">
                                                {p.nome}{' '}
                                                <span className="text-on-surface-variant">· {p.papel_label}</span>
                                            </p>
                                            <p className="text-xs text-on-surface-variant truncate">
                                                {p.projeto_titulo}
                                                {p.escola ? ` · ${p.escola}` : ''}
                                            </p>
                                            {p.presente && (
                                                <p className="text-xs font-semibold text-green-800">Já fez check-in</p>
                                            )}
                                        </div>
                                        <Button type="button" variant="outline" onClick={() => abrir(p.projeto_id)}>
                                            Abrir ficha
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            )}
        </AppShell>
    );
}
