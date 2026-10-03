/**
 * Os selos que as equipes do evento precisam ver antes de olhar para um
 * projeto (Sprints 161–162): **credenciamento fora do prazo** aprovado — com a
 * data prevista de chegada — e os **suportes aprovados** (acompanhante,
 * intérprete). O backend manda `sinalizacoes` nulo quando não há nada, e o
 * componente some.
 *
 * `compacto` é a versão de lista (uma linha de selos); a completa, da ficha,
 * mostra também a observação e o documento do acompanhante.
 */
export default function SinalizacoesProjeto({ sinalizacoes, compacto = false }) {
    if (!sinalizacoes) return null;

    const { fora_prazo: foraPrazo, suportes = [] } = sinalizacoes;

    if (compacto) {
        return (
            <div className="flex flex-wrap gap-1 mt-1">
                {foraPrazo && (
                    <span className="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-900">
                        <span className="material-symbols-outlined text-[14px]">schedule</span>
                        Credenciamento fora do prazo{foraPrazo.previsto_label ? ` · chega ${foraPrazo.previsto_label}` : ''}
                    </span>
                )}
                {suportes.map((s) => (
                    <span key={s.id} className="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-primary-fixed text-primary-container" title={s.resumo}>
                        <span className="material-symbols-outlined text-[14px]">accessibility_new</span>
                        {s.tipo_label}
                    </span>
                ))}
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {foraPrazo && (
                <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="note">
                    <p className="font-semibold flex items-center gap-1">
                        <span className="material-symbols-outlined text-[18px]">schedule</span>
                        Credenciamento fora do prazo aprovado
                    </p>
                    <p>
                        {foraPrazo.previsto_label
                            ? <>Chegada prevista: <strong>{foraPrazo.previsto_label}</strong>.</>
                            : 'Sem data prevista de chegada.'}
                        {foraPrazo.observacao ? ` ${foraPrazo.observacao}` : ''}
                    </p>
                </div>
            )}
            {suportes.length > 0 && (
                <div className="rounded-lg border border-primary-container/30 bg-primary-fixed/40 p-3 text-sm" role="note">
                    <p className="font-semibold text-primary-container flex items-center gap-1">
                        <span className="material-symbols-outlined text-[18px]">accessibility_new</span>
                        Suporte aprovado
                    </p>
                    <ul className="list-disc pl-5 text-on-surface">
                        {suportes.map((s) => (
                            <li key={s.id}>
                                {s.resumo}
                                {s.documento ? <span className="text-on-surface-variant"> · documento {s.documento}</span> : null}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
