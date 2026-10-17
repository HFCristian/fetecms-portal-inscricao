/**
 * Código do projeto na lista final, turno e estande (Sprint 217), em selos —
 * o que o balcão diz à equipe logo depois de conferir os documentos, e o que
 * está impresso no crachá dela.
 *
 * O que ainda não existe some, em vez de virar "—": sem turnos gerados não há
 * turno, sem estandes não há estande. Sem nenhum dos três, nada é desenhado.
 */
export default function LocalProjeto({ projeto, className = '' }) {
    const selos = [
        projeto?.codigo && { chave: 'codigo', icone: 'tag', texto: projeto.codigo, mono: true },
        projeto?.turno_label && { chave: 'turno', icone: 'schedule', texto: projeto.turno_label },
        projeto?.estande != null && { chave: 'estande', icone: 'storefront', texto: `Estande ${projeto.estande}` },
    ].filter(Boolean);

    if (selos.length === 0) return null;

    return (
        <div className={`flex flex-wrap gap-1 ${className}`}>
            {selos.map((s) => (
                <span
                    key={s.chave}
                    className={`inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-primary-fixed text-primary-container ${s.mono ? 'font-mono' : ''}`}
                >
                    <span className="material-symbols-outlined text-[14px]" aria-hidden="true">{s.icone}</span>
                    {s.texto}
                </span>
            ))}
        </div>
    );
}
