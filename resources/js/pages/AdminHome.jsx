import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { getDashboard } from '../lib/admin.js';

// Cards do painel. `status` = submetidos/rascunho; `genero` = mulheres/homens/outros;
// `categoria` = quantos projetos em cada categoria da feira; `camiseta` = quantas
// camisetas de cada tamanho informado (o número que a organização usa para
// encomendar); `classe` = quantos alunos em cada classe escolar, por série.
const CARDS = [
    { key: 'projetos_total', label: 'Projetos (total)', icon: 'folder', verMais: '/admin/projetos-por-area' },
    { type: 'status', label: 'Projetos por status', icon: 'donut_large' },
    { type: 'categoria', label: 'Projetos por categoria', icon: 'category' },
    { type: 'genero', generoKey: 'orientadores_genero', label: 'Orientadores', icon: 'person' },
    { type: 'genero', generoKey: 'alunos_genero', label: 'Alunos', icon: 'school' },
    { type: 'genero', generoKey: 'coorientadores_genero', label: 'Coorientadores', icon: 'group' },
    { type: 'camiseta', camisetaKey: 'orientadores_camisetas', label: 'Camisetas · Orientadores', icon: 'apparel' },
    { type: 'camiseta', camisetaKey: 'alunos_camisetas', label: 'Camisetas · Alunos', icon: 'apparel' },
    { type: 'camiseta', camisetaKey: 'coorientadores_camisetas', label: 'Camisetas · Coorientadores', icon: 'apparel' },
    // Um card por classe escolar; a ordem e os rótulos vêm do backend.
    { type: 'classe', classeIndex: 0, icon: 'school' },
    { type: 'classe', classeIndex: 1, icon: 'school' },
    { type: 'classe', classeIndex: 2, icon: 'school' },
    { key: 'escolas_com_projeto', label: 'Escolas com projeto', icon: 'apartment', verMais: '/admin/projetos-por-escola' },
    { key: 'cidades_com_projeto', label: 'Cidades com projeto', icon: 'location_city', verMais: '/admin/projetos-por-cidade' },
    { key: 'estados_com_projeto', label: 'Estados com projeto', icon: 'map', verMais: '/admin/projetos-por-estado' },
];

function VerMais({ to }) {
    return (
        <Link
            to={to}
            className="flex flex-row items-center justify-center text-sm font-semibold text-primary-container hover:text-primary hover:border-b-2 border-primary transition ease-in-out w-2/4"
        >
            <p>Ver mais</p>
            <span className="material-symbols-outlined text-[18px]">chevron_right</span>
        </Link>
    );
}

// Card de contagens lado a lado + legenda embaixo (usado por gênero e por categoria).
function Breakdown({ colunas, label }) {
    return (
        <>
            <div className="flex gap-3 py-2">
                {colunas.map((c) => (
                    <div key={c.rotulo} className="flex-1 min-w-0">
                        <div className="text-3xl font-bold text-primary-container">{c.valor ?? 0}</div>
                        <div className="text-xs text-on-surface-variant leading-tight">{c.rotulo}</div>
                    </div>
                ))}
            </div>
            <div className="text-sm text-on-surface-variant">{label}</div>
        </>
    );
}

// Recorte por gênero: mulheres (F), homens (M) e outros/não informado.
function GeneroBreakdown({ dados, label }) {
    const g = dados ?? { f: 0, m: 0, outros: 0 };
    return (
        <Breakdown
            label={label}
            colunas={[
                { valor: g.f, rotulo: 'Mulheres' },
                { valor: g.m, rotulo: 'Homens' },
                { valor: g.outros, rotulo: 'Outros/N.I.' },
            ]}
        />
    );
}

// Projetos cadastrados em cada categoria da feira (ordem vinda do backend).
function CategoriaBreakdown({ dados, label }) {
    return (
        <Breakdown
            label={label}
            colunas={(dados ?? []).map((c) => ({ valor: c.total, rotulo: c.label }))}
        />
    );
}

// Camisetas por tamanho (PP…XG). São seis baldes, demais para a linha única do
// Breakdown, então saem numa grade de quatro colunas. Quem não informou tamanho
// não aparece: a soma pode ficar abaixo do número grande, que é o total de gente.
function CamisetaBreakdown({ dados, label }) {
    const d = dados ?? { total: 0, tamanhos: [] };
    return (
        <>
            <div className="text-3xl font-bold text-primary-container pt-2">{d.total ?? 0}</div>
            <div className="grid grid-cols-4 gap-x-2 gap-y-1 w-full py-2">
                {(d.tamanhos ?? []).map((t) => (
                    <div key={t.tamanho} className="min-w-0">
                        <div className="text-lg font-semibold text-on-surface">{t.total ?? 0}</div>
                        <div className="text-[11px] text-on-surface-variant leading-tight truncate">{t.tamanho}</div>
                    </div>
                ))}
            </div>
            <div className="text-sm text-on-surface-variant">{label}</div>
        </>
    );
}

// Alunos de uma classe escolar (Fundamental I/II ou Médio), com a quebra por
// série. Aluno sem série não aparece na quebra, então a soma das séries pode
// ficar abaixo do número grande — que é o total de alunos da classe.
function ClasseBreakdown({ dados }) {
    if (!dados) return null;
    return (
        <>
            <div className="text-3xl font-bold text-primary-container pt-2">{dados.total ?? 0}</div>
            <div className="grid grid-cols-3 gap-x-2 gap-y-1 w-full py-2">
                {(dados.series ?? []).map((s) => (
                    <div key={s.serie} className="min-w-0">
                        <div className="text-lg font-semibold text-on-surface">{s.total ?? 0}</div>
                        <div className="text-[11px] text-on-surface-variant leading-tight truncate">{s.serie}</div>
                    </div>
                ))}
            </div>
            <div className="text-sm text-on-surface-variant">Alunos · {dados.label}</div>
        </>
    );
}

export default function AdminHome() {
    const [m, setM] = useState(null);

    useEffect(() => { getDashboard().then(setM).catch(() => setM({})); }, []);

    return (
        <AppShell>
            <h1 className="font-display text-2xl font-semibold text-primary mb-1">Painel do Administrador</h1>
            <p className="text-on-surface-variant mb-6">Visão geral da XVI FETECMS.</p>

            {!m ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 mb-10">
                    {CARDS.map((c) => (
                        <div key={c.key ?? c.generoKey ?? c.camisetaKey ?? `${c.type}-${c.classeIndex ?? ''}`} className="flex flex-col items-center text-center gap-2 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
                            <span className="material-symbols-outlined text-primary-container text-2xl">{c.icon}</span>

                            {c.type === 'status' ? (
                                <>
                                    <div className="flex gap-4 py-2">
                                        <div>
                                            <div className="text-3xl font-bold text-secondary">{m.projetos_submetidos ?? 0}</div>
                                            <div className="text-xs text-on-surface-variant">Submetidos</div>
                                        </div>
                                        <div>
                                            <div className="text-3xl font-bold text-primary-container">{m.projetos_rascunho ?? 0}</div>
                                            <div className="text-xs text-on-surface-variant">Rascunho</div>
                                        </div>
                                    </div>
                                    <div className="text-sm text-on-surface-variant">{c.label}</div>
                                </>
                            ) : c.type === 'categoria' ? (
                                <CategoriaBreakdown dados={m.projetos_categoria} label={c.label} />
                            ) : c.type === 'genero' ? (
                                <GeneroBreakdown dados={m[c.generoKey]} label={c.label} />
                            ) : c.type === 'camiseta' ? (
                                <CamisetaBreakdown dados={m[c.camisetaKey]} label={c.label} />
                            ) : c.type === 'classe' ? (
                                <ClasseBreakdown dados={(m.alunos_classes ?? [])[c.classeIndex]} />
                            ) : (
                                <>
                                    <div className='py-2'>
                                        <div className="text-3xl font-bold text-on-surface">{m[c.key] ?? 0}</div>
                                        <div className="text-sm text-on-surface-variant">{c.label}</div>
                                    </div>
                                </>
                            )}

                            {c.verMais && <VerMais to={c.verMais} />}
                        </div>
                    ))}
                </div>
            )}
        </AppShell>
    );
}
