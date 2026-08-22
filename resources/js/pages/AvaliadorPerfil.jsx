import { useEffect, useState } from 'react';
import AppShell from '../components/AppShell.jsx';
import { Field, Select, Button, Alert } from '../components/ui.jsx';
import SubareaCombobox from '../components/SubareaCombobox.jsx';
import { useAuth, extractErrors } from '../lib/auth.jsx';
import { getPerfilAvaliador, salvarClassificacaoAvaliador } from '../lib/avaliador.js';
import { loadAreas, loadSubareas, criarSubarea } from '../lib/catalogos.js';

/** Um número do perfil, em card. */
function Estatistica({ icone, valor, rotulo, detalhe }) {
    return (
        <div className="flex flex-col items-center text-center gap-1 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
            <span className="material-symbols-outlined text-[32px] text-primary-container">{icone}</span>
            <span className="font-display text-3xl font-bold text-primary">{valor}</span>
            <span className="text-sm font-semibold text-on-surface">{rotulo}</span>
            {detalhe && <span className="text-xs text-on-surface-variant">{detalhe}</span>}
        </div>
    );
}

function Dado({ label, children }) {
    return (
        <div>
            <p className="text-xs font-semibold text-on-surface-variant uppercase tracking-wide">{label}</p>
            <p className="text-sm text-on-surface">{children || '—'}</p>
        </div>
    );
}

const plural = (n, singular, pluralForma) => `${n} ${n === 1 ? singular : pluralForma}`;

/**
 * Perfil do avaliador: o que ele já acumulou na feira (avaliações concluídas,
 * carga horária do certificado e posição no ranking de quem mais avaliou) e a
 * troca da própria área/subárea — liberada só antes do período de avaliação.
 */
export default function AvaliadorPerfil() {
    const { user, setUser } = useAuth();
    const [dados, setDados] = useState(null); // perfil da API | false (erro)
    const [areas, setAreas] = useState([]);
    const [subareas, setSubareas] = useState([]);
    const [form, setForm] = useState({ area_id: '', subarea_id: '' });
    const [errors, setErrors] = useState({});
    const [alerta, setAlerta] = useState('');
    const [sucesso, setSucesso] = useState('');
    const [salvando, setSalvando] = useState(false);

    const err = (campo) => errors[campo]?.[0];

    useEffect(() => {
        getPerfilAvaliador()
            .then((d) => {
                setDados(d);
                setForm({ area_id: d.area_id ?? '', subarea_id: d.subarea_id ?? '' });
            })
            .catch(() => setDados(false));
    }, []);

    // Catálogo de áreas: só faz falta quando a troca ainda está aberta.
    useEffect(() => {
        if (!dados?.pode_trocar_area) return;
        loadAreas().then(setAreas).catch(() => setAreas([]));
    }, [dados?.pode_trocar_area]);

    // Subáreas seguem a área escolhida no formulário.
    useEffect(() => {
        if (!form.area_id) { setSubareas([]); return; }
        loadSubareas(form.area_id).then(setSubareas).catch(() => setSubareas([]));
    }, [form.area_id]);

    function trocarArea(areaId) {
        setSucesso('');
        // Trocar de área invalida a subárea escolhida antes (ela era de outra área).
        setForm({ area_id: areaId, subarea_id: '' });
    }

    async function salvar(e) {
        e.preventDefault();
        setSalvando(true); setAlerta(''); setSucesso(''); setErrors({});
        try {
            const atualizado = await salvarClassificacaoAvaliador({
                area_id: form.area_id ? Number(form.area_id) : null,
                subarea_id: form.subarea_id ? Number(form.subarea_id) : null,
            });
            setDados(atualizado);
            setSucesso('Área de atuação atualizada.');
            // O cabeçalho do painel lê a área do usuário em memória: mantém em dia.
            if (user?.avaliador_profile) {
                setUser({
                    ...user,
                    avaliador_profile: {
                        ...user.avaliador_profile,
                        area_id: atualizado.area_id, area: atualizado.area,
                        subarea_id: atualizado.subarea_id, subarea: atualizado.subarea,
                    },
                });
            }
        } catch (error) {
            setErrors(extractErrors(error));
            setAlerta(error?.response?.data?.message || 'Não foi possível salvar a área.');
        } finally {
            setSalvando(false);
        }
    }

    const est = dados?.estatisticas;
    const subareaSelecionada = subareas.find((s) => String(s.id) === String(form.subarea_id)) ?? null;
    const mudou = String(form.area_id) !== String(dados?.area_id ?? '')
        || String(form.subarea_id) !== String(dados?.subarea_id ?? '');

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Meu perfil</h1>
            <p className="text-on-surface-variant mb-4">
                Seus números na FETECMS e a área em que você avalia.
            </p>

            {dados === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : dados === false ? (
                <div className="max-w-3xl"><Alert>Não foi possível carregar seu perfil.</Alert></div>
            ) : (
                <div className="max-w-3xl space-y-6">
                    <section className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <Estatistica
                            icone="fact_check"
                            valor={est.avaliacoes_concluidas}
                            rotulo="Projetos avaliados"
                            detalhe={`${plural(dados.projetos_designados, 'projeto designado', 'projetos designados')} a você`}
                        />
                        <Estatistica
                            icone="workspace_premium"
                            valor={est.certificado_label}
                            rotulo="Certificado"
                            detalhe={`${est.por_avaliacao_label} por avaliação concluída`}
                        />
                        <Estatistica
                            icone="trophy"
                            valor={est.posicao ? `${est.posicao}º` : '—'}
                            rotulo="No ranking de avaliadores"
                            detalhe={est.posicao
                                ? `entre ${plural(est.total_no_ranking, 'avaliador', 'avaliadores')}${est.empate ? ' · posição dividida' : ''}`
                                : 'Conclua sua primeira avaliação para entrar no ranking'}
                        />
                    </section>

                    <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                        <div className="px-4 py-3 bg-surface-variant/40">
                            <h2 className="font-display font-semibold text-on-surface">Seus dados</h2>
                        </div>
                        <div className="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <Dado label="Nome">{dados.nome}</Dado>
                            <Dado label="E-mail">{dados.email}</Dado>
                            <Dado label="Titulação">{dados.titulacao}</Dado>
                            <Dado label="Limite de avaliações">
                                {dados.limite_avaliacoes ?? `${dados.max_por_avaliador} (padrão do edital)`}
                            </Dado>
                        </div>
                    </section>

                    <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow overflow-hidden">
                        <div className="px-4 py-3 bg-surface-variant/40">
                            <h2 className="font-display font-semibold text-on-surface">Área de atuação</h2>
                        </div>

                        {dados.pode_trocar_area ? (
                            <form className="p-4 space-y-4" onSubmit={salvar}>
                                <p className="text-sm text-on-surface-variant">
                                    É por ela que os projetos são distribuídos a você. Você pode alterá-la
                                    enquanto o período de avaliação não começa
                                    {dados.liberada_em_label ? <> — a avaliação abre em <strong>{dados.liberada_em_label}</strong></> : null}.
                                </p>

                                {alerta && <Alert>{alerta}</Alert>}
                                {sucesso && <Alert type="info">{sucesso}</Alert>}

                                {dados.projetos_designados > 0 && (
                                    <Alert type="info">
                                        Você já tem {plural(dados.projetos_designados, 'projeto designado', 'projetos designados')}.
                                        Trocar de área não muda essas designações — só a organização pode refazê-las.
                                    </Alert>
                                )}

                                <Field label="Área do conhecimento" required error={err('area_id')}>
                                    <Select
                                        id="area_id"
                                        aria-label="Área do conhecimento"
                                        value={form.area_id}
                                        error={err('area_id')}
                                        onChange={(e) => trocarArea(e.target.value)}
                                    >
                                        <option value="">Selecione</option>
                                        {areas.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
                                    </Select>
                                </Field>

                                <Field
                                    label="Subárea"
                                    error={err('subarea_id')}
                                    hint="Opcional. Com subárea, a distribuição tenta casar o projeto com ela antes de cair na área."
                                >
                                    <SubareaCombobox
                                        options={subareas}
                                        value={subareaSelecionada}
                                        onChange={(sel) => { setSucesso(''); setForm((f) => ({ ...f, subarea_id: sel?.id ?? '' })); }}
                                        create={form.area_id ? (nome) => criarSubarea(form.area_id, nome) : undefined}
                                        disabled={!form.area_id}
                                        placeholder={form.area_id ? 'Digite para buscar ou criar…' : 'Escolha a área primeiro'}
                                    />
                                </Field>

                                <div className="flex justify-end">
                                    <Button type="submit" loading={salvando} disabled={!mudou || !form.area_id}>
                                        Salvar área
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <div className="p-4 space-y-3">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <Dado label="Área">{dados.area}</Dado>
                                    <Dado label="Subárea">{dados.subarea}</Dado>
                                </div>
                                <Alert type="info">
                                    O período de avaliação já começou: a área não pode mais ser alterada, porque os
                                    projetos já foram distribuídos por ela. Precisando trocar, fale com a organização.
                                </Alert>
                            </div>
                        )}
                    </section>
                </div>
            )}
        </AppShell>
    );
}
