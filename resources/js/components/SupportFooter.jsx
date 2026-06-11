// Rodapé com o e-mail de contato do suporte da FETECMS.
export default function SupportFooter({ className = '' }) {
    return (
        <footer className={`text-xs text-on-surface-variant text-center ${className}`}>
            Suporte:{' '}
            <a href="mailto:fetecms@gmail.com" className="font-semibold text-primary-container hover:underline">
                fetecms@gmail.com
            </a>
        </footer>
    );
}
