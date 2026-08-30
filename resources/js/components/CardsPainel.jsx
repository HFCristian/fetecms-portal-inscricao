import { Link } from 'react-router-dom';

/**
 * Os cards de número do painel do admin.
 *
 * Moram aqui porque duas telas os desenham: **Dashboards** (todos, agrupados por
 * assunto) e **Projetos** (só o recorte de projetos e localidades). O dado de
 * ambas é o mesmo `GET /admin/dashboard`.
 *
 * Regra dos números, herdada do `AdminDashboardService`: fora "Projetos (total)"
 * e "Projetos por status" — que existem justamente para mostrar o rascunho —,
 * **todo card conta apenas projetos submetidos**.
 */
export function VerMais({ to }) {
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

/** Chave estável de um card, para o `key` da lista. */
export function chaveCard(c) {
    return c.key ?? c.generoKey ?? c.camisetaKey ?? `${c.type}-${c.classeIndex ?? ''}`;
}

/**
 * Desenha um card a partir da sua descrição e do payload do dashboard.
 *
 * `verMais` mora na descrição do card, e não aqui, porque o mesmo card aparece
 * com e sem o atalho: em "Projetos" o total leva à lista, em "Dashboards" ele é
 * só número.
 */
export function CardPainel({ card: c, dados: m }) {
    return (
        <div className="flex flex-col items-center text-center gap-2 bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
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
                <div className="py-2">
                    <div className="text-3xl font-bold text-on-surface">{m[c.key] ?? 0}</div>
                    <div className="text-sm text-on-surface-variant">{c.label}</div>
                </div>
            )}

            {c.verMais && <VerMais to={c.verMais} />}
        </div>
    );
}

/** Uma grade de cards — o corpo de qualquer seção do painel. */
export function GradeCards({ cards, dados }) {
    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
            {cards.map((c) => <CardPainel key={chaveCard(c)} card={c} dados={dados} />)}
        </div>
    );
}
