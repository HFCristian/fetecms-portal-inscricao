/** A situação de uma recomendação do comitê, em pílula (Sprint 193). */
const ESTILOS = {
    pendente: 'bg-yellow-100 text-yellow-900 border-yellow-300',
    aprovada: 'bg-secondary-container text-on-secondary-container border-secondary/30',
    rejeitada: 'bg-error-container text-on-error-container border-error/30',
};

export default function StatusRecomendacao({ status, label }) {
    return (
        <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap ${ESTILOS[status] ?? ''}`}>
            {label}
        </span>
    );
}
