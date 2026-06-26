import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

// Sem chamadas reais à API do chat.
vi.mock('../lib/chat.js', () => ({
    getMinhaConversa: vi.fn(() => Promise.resolve({ mensagens: [], suporte_visto_em: null })),
    enviarMensagem: vi.fn(),
    getNaoLidas: vi.fn(() => Promise.resolve({ nao_lidas: true, total: 2 })),
    foiVista: () => false,
}));

import ChatWidget from './ChatWidget.jsx';

describe('ChatWidget — bolinha de não lidas', () => {
    it('mostra a bolinha quando há mensagens não lidas e a esconde ao abrir o chat', async () => {
        render(<ChatWidget />);

        // Aparece após o checar() inicial (chat fechado).
        await screen.findByLabelText('Há mensagens não lidas');

        // Abrir o chat limpa a bolinha (a abertura marca como visto no backend).
        fireEvent.click(screen.getByLabelText('Abrir chat de suporte'));

        await waitFor(() => {
            expect(screen.queryByLabelText('Há mensagens não lidas')).toBeNull();
        });
    });
});
