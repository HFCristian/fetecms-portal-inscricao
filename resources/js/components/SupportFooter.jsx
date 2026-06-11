// Rodapé com o e-mail de contato do suporte da FETECMS.
export default function SupportFooter({ className = '' }) {
    return (
        <footer className={`max-w-5xl w-full py-1 px-5 rounded-xl bg-surface-variant text-xs font-black text-center ${className}`}>
            Suporte:{' '}
            <a href="mailto:fetecms@gmail.com" className="text-primary-container hover:underline">
                fetecms@gmail.com
            </a>
        </footer>
    );
}
