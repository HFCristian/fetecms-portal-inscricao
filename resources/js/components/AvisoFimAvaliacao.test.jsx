import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }));

import AvisoFimAvaliacao from './AvisoFimAvaliacao.jsx';

const JANELA = {
    aberta: true, iniciada: true, encerrada: false,
    de_label: '20/10/2026 08:00', ate_label: '30/10/2026 23:59',
    avaliacao_encerrada: true, avaliacao_encerrada_em_label: '15/10/2026 23:59',
    is_demo: false, modo_teste: false,
};

const render1 = (janela, temProjetoSubmetido = true) =>
    render(<AvisoFimAvaliacao janela={janela} temProjetoSubmetido={temProjetoSubmetido} />);

describe('AvisoFimAvaliacao', () => {
    beforeEach(() => navigate.mockReset());

    it('avisa o fim da avaliação e leva à aba de ajustes e pareceres', () => {
        render1(JANELA);

        expect(screen.getByText('A avaliação online terminou')).toBeInTheDocument();
        expect(screen.getByText('15/10/2026 23:59')).toBeInTheDocument();
        // Com o período aberto, diz até quando dá para responder.
        expect(screen.getByText('30/10/2026 23:59')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /AJUSTES E PARECERES/i }));
        expect(navigate).toHaveBeenCalledWith('/ajustes');
    });

    it('com o período ainda por abrir, diz a data em que a aba abre', () => {
        render1({ ...JANELA, aberta: false, iniciada: false });

        expect(screen.getByText(/abre em/)).toBeInTheDocument();
        expect(screen.getByText('20/10/2026 08:00')).toBeInTheDocument();
        // O botão continua: a aba está no menu e explica a situação.
        expect(screen.getByRole('button', { name: /AJUSTES E PARECERES/i })).toBeInTheDocument();
    });

    it('sem data marcada, diz que a organização ainda não abriu o período', () => {
        render1({ ...JANELA, aberta: false, iniciada: false, de_label: null, ate_label: null });

        expect(screen.getByText(/ainda não abriu o período de ajustes/)).toBeInTheDocument();
    });

    /** A nota é da organização — aqui como na aba. */
    it('não mostra nota nenhuma', () => {
        render1({ ...JANELA, media: 7.35, nota_maxima: 10 });

        expect(screen.queryByText(/7,35|7\.35/)).not.toBeInTheDocument();
    });

    it('não aparece antes do fim da avaliação', () => {
        const { container } = render1({ ...JANELA, avaliacao_encerrada: false });

        expect(container).toBeEmptyDOMElement();
    });

    it('não aparece para quem não submeteu projeto nenhum', () => {
        const { container } = render1(JANELA, false);

        expect(container).toBeEmptyDOMElement();
    });

    /** Encerrado o período de ajustes não há mais o que acompanhar. */
    it('some depois que o período de ajustes termina', () => {
        const { container } = render1({ ...JANELA, aberta: false, encerrada: true });

        expect(container).toBeEmptyDOMElement();
    });

    it('não quebra enquanto a janela não chegou do servidor', () => {
        const { container } = render1(null);

        expect(container).toBeEmptyDOMElement();
    });
});
