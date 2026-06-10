import { useEffect, useState, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Button, Alert, useConfirm } from '../components/ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getResumo, submeterProjeto } from '../lib/submissao.js';

function Linha({ label, valor }) {
    return (
        <div>
            <dt className="text-xs text-on-surface-variant">{label}</dt>
            <dd className="text-sm text-on-surface">{valor || <span className="italic text-on-surface-variant">—</span>}</dd>
        </div>
    );
}

export default function Resumo() {
    const { id } = useParams();
    const navigate = useNavigate();
    const [confirm, confirmDialog] = useConfirm();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [alert, setAlert] = useState('');

    const carregar = useCallback(() => {
        setLoading(true);
        getResumo(id).then(setData).finally(() => setLoading(false));
    }, [id]);

    useEffect(() => carregar(), [carregar]);

    async function confirmar() {
        const ok = await confirm({
            title: 'Confirmar submissão',
            message: 'Confirmar a submissão? Após submeter, o projeto NÃO poderá mais ser editado (previsto em edital).',
            confirmLabel: 'Submeter',
        });
        if (!ok) return;
        setAlert('');
        setSubmitting(true);
        try {
            await submeterProjeto(id);
            navigate('/projetos', { replace: true });
        } catch (e) {
            setAlert(extractErrors(e).message || 'Não foi possível submeter.');
            carregar(); // atualiza o checklist
        } finally {
            setSubmitting(false);
        }
    }

    if (loading || !data) {
        return (
            <AppShell>
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="material-symbols-outlined animate-spin">progress_activity</span>
                </div>
            </AppShell>
        );
    }

    const { projeto, integrantes, documentos, pendencias, pode_submeter } = data;
    const jaSubmetido = projeto.status === 'submetido';

    return (
        <AppShell>
            <Link to="/projetos" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-2">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span>
                Meus projetos
            </Link>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Resumo da Inscrição</h1>
            <p className="text-on-surface-variant mb-6">Confira os dados e os integrantes antes de confirmar a submissão à XVI FETECMS.</p>

            {alert && <div className="mb-4"><Alert>{alert}</Alert></div>}

            {jaSubmetido && (
                <div className="mb-4"><Alert type="info">Este projeto já foi submetido em {projeto.submitted_at ? new Date(projeto.submitted_at).toLocaleString('pt-BR') : ''}.</Alert></div>
            )}

            {/* Dados do projeto */}
            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-5">
                <h2 className="font-display text-primary font-semibold mb-4">Dados do Projeto</h2>
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Linha label="Título" valor={projeto.titulo} />
                    <Linha label="Instituição" valor={projeto.nomes?.instituicao} />
                    <Linha label="Categoria" valor={projeto.categoria_label} />
                    <Linha label="Área / Subárea" valor={[projeto.nomes?.area, projeto.nomes?.subarea].filter(Boolean).join(' · ')} />
                    <Linha label="Localização" valor={[projeto.nomes?.cidade, projeto.nomes?.estado].filter(Boolean).join(' / ')} />
                    <Linha label="Palavras-chave" valor={(projeto.palavras_chave || []).join(', ')} />
                </dl>
            </section>

            {/* Integrantes */}
            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-5">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="font-display text-primary font-semibold">Integrantes</h2>
                    <Link to={`/projetos/${id}/integrantes`} className="text-sm text-primary-container hover:underline">Editar equipe</Link>
                </div>
                <p className="text-sm font-semibold text-on-surface-variant mb-1">Alunos ({integrantes.alunos.length})</p>
                <ul className="text-sm text-on-surface mb-3 list-disc pl-5">
                    {integrantes.alunos.length === 0 && <li className="text-on-surface-variant list-none pl-0">Nenhum aluno cadastrado.</li>}
                    {integrantes.alunos.map((a) => <li key={a.id}>{a.nome} — {a.email}</li>)}
                </ul>
                <p className="text-sm">
                    <span className="font-semibold text-on-surface-variant">Coorientador: </span>
                    {integrantes.coorientador ? integrantes.coorientador.nome : <span className="text-on-surface-variant">não informado (opcional)</span>}
                </p>
            </section>

            {/* Documentos */}
            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-5">
                <h2 className="font-display text-primary font-semibold mb-3">Documentos</h2>
                <ul className="text-sm text-on-surface list-disc pl-5">
                    {documentos.length === 0 && <li className="text-on-surface-variant list-none pl-0">Nenhum documento anexado.</li>}
                    {documentos.map((d) => <li key={d.id}>{d.tipo_label}: {d.nome_original}</li>)}
                </ul>
            </section>

            {/* Checklist */}
            <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 mb-6">
                <h2 className="font-display text-primary font-semibold mb-3">Checklist de submissão</h2>
                {pendencias.length === 0 ? (
                    <p className="flex items-center gap-2 text-secondary text-sm">
                        <span className="material-symbols-outlined" style={{ fontVariationSettings: "'FILL' 1" }}>check_circle</span>
                        Tudo certo! O projeto está pronto para submissão.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {pendencias.map((p) => (
                            <li key={p.code} className="flex items-center gap-2 text-sm text-error">
                                <span className="material-symbols-outlined text-[20px]">cancel</span>
                                {p.message}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {/* Ações */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                {jaSubmetido ? (
                    <div className="flex flex-wrap gap-3">
                        <Button variant="outline" type="button" onClick={() => navigate('/projetos')}>
                            <span className="material-symbols-outlined text-[20px]">arrow_back</span>
                            Voltar aos projetos
                        </Button>
                        <Button variant="outline" type="button" onClick={() => navigate(`/projetos/${id}/integrantes`)}>
                            <span className="material-symbols-outlined text-[20px]">groups</span>
                            Ver integrantes
                        </Button>
                    </div>
                ) : (
                    <>
                        <div className="flex flex-wrap gap-3">
                            <Button variant="outline" type="button" onClick={() => navigate('/projetos')}>
                                <span className="material-symbols-outlined text-[20px]">arrow_back</span>
                                Voltar aos projetos
                            </Button>
                            <Button variant="outline" type="button" onClick={() => navigate(`/projetos/${id}/editar`)}>
                                <span className="material-symbols-outlined text-[20px]">edit</span>
                                Editar projeto
                            </Button>
                            <Button variant="outline" type="button" onClick={() => navigate(`/projetos/${id}/integrantes`)}>
                                <span className="material-symbols-outlined text-[20px]">groups</span>
                                Integrantes
                            </Button>
                        </div>
                        <Button variant="success" type="button" loading={submitting} disabled={!pode_submeter} onClick={confirmar}
                            title={pode_submeter ? '' : 'Resolva as pendências do checklist'}>
                            <span className="material-symbols-outlined text-[20px]">verified</span>
                            CONFIRMAR SUBMISSÃO
                        </Button>
                    </>
                )}
            </div>
            {confirmDialog}
        </AppShell>
    );
}
