import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getAvisoAtivo = vi.fn();
const marcarAvisoVisto = vi.fn(() => Promise.resolve());
const fecharAviso = vi.fn(() => Promise.resolve());

vi.mock('../lib/avisos.js', () => ({
    getAvisoAtivo: (...a) => getAvisoAtivo(...a),
    marcarAvisoVisto: (...a) => marcarAvisoVisto(...a),
    fecharAviso: (...a) => fecharAviso(...a),
}));

import AvisoCard from './AvisoCard.jsx';

const AVISO = {
    id: 3,
    titulo: 'As inscrições estão se encerrando',
    mensagem: 'Faltam 45 minutos.\nRevise seu checklist.',
    publicado_em: '22/08/2026 19:00',
};

describe('AvisoCard', () => {
    beforeEach(() => {
        getAvisoAtivo.mockReset();
        marcarAvisoVisto.mockClear();
        fecharAviso.mockClear();
        getAvisoAtivo.mockResolvedValue(AVISO);
    });

    it('mostra o aviso no ar', async () => {
        render(<AvisoCard />);

        expect(await screen.findByText('As inscrições estão se encerrando')).toBeInTheDocument();
        expect(screen.getByText(/Faltam 45 minutos/)).toBeInTheDocument();
        expect(screen.getByText('Publicado em 22/08/2026 19:00')).toBeInTheDocument();
    });

    it('registra a visualização quando o card chega à tela', async () => {
        render(<AvisoCard />);

        await screen.findByText('As inscrições estão se encerrando');
        await waitFor(() => expect(marcarAvisoVisto).toHaveBeenCalledWith(3));
        // Uma vez só, mesmo com o polling repetindo a mesma resposta.
        expect(marcarAvisoVisto).toHaveBeenCalledTimes(1);
    });

    it('some da tela ao ser fechado e registra o fechamento', async () => {
        render(<AvisoCard />);

        fireEvent.click(await screen.findByLabelText('Fechar aviso'));

        await waitFor(() => expect(fecharAviso).toHaveBeenCalledWith(3));
        await waitFor(() => expect(screen.queryByText('As inscrições estão se encerrando')).not.toBeInTheDocument());
    });

    it('some da tela mesmo se o registro do fechamento falhar', async () => {
        fecharAviso.mockRejectedValue(new Error('offline'));
        render(<AvisoCard />);

        fireEvent.click(await screen.findByLabelText('Fechar aviso'));

        await waitFor(() => expect(screen.queryByText('As inscrições estão se encerrando')).not.toBeInTheDocument());
    });

    it('não renderiza nada quando não há aviso no ar', async () => {
        getAvisoAtivo.mockResolvedValue(null);
        const { container } = render(<AvisoCard />);

        await waitFor(() => expect(getAvisoAtivo).toHaveBeenCalled());
        expect(container).toBeEmptyDOMElement();
    });

    it('nem consulta a API para quem não é orientador', async () => {
        render(<AvisoCard ativo={false} />);

        expect(getAvisoAtivo).not.toHaveBeenCalled();
    });
});
