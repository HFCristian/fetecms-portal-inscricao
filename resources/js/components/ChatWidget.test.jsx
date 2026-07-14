import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

// Sem chamadas reais à API do chat.
vi.mock('../lib/chat.js', () => ({
    getMinhaConversa: vi.fn(() => Promise.resolve({ mensagens: [], suporte_visto_em: null })),
    enviarMensagem: vi.fn(),
    getNaoLidas: vi.fn(() => Promise.resolve({ nao_lidas: true, total: 2 })),
    dispensarDicaChat: vi.fn(() => Promise.resolve({ chat_dica_dispensada: true })),
    foiVista: () => false,
}));
// Usuário orientador na sua home, com a dica ainda não dispensada.
vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({ user: { id: 1, role: 'orientador', chat_dica_dispensada: false }, setUser: vi.fn() }),
    homeFor: () => '/projetos',
}));
vi.mock('react-router-dom', () => ({ useLocation: () => ({ pathname: '/projetos' }) }));

import ChatWidget from './ChatWidget.jsx';
import { dispensarDicaChat } from '../lib/chat.js';

describe('ChatWidget — bolinha de não lidas', () => {
    it('mostra a bolinha quando há mensagens não lidas e a esconde ao abrir o chat', async () => {
        render(<ChatWidget />);
        await screen.findByLabelText('Há mensagens não lidas');
        fireEvent.click(screen.getByLabelText('Abrir chat de suporte'));
        await waitFor(() => {
            expect(screen.queryByLabelText('Há mensagens não lidas')).toBeNull();
        });
    });
});

describe('ChatWidget — balão de apresentação', () => {
    it('mostra o balão na home e some ao clicar em fechar (persistindo no backend)', async () => {
        render(<ChatWidget />);
        expect(await screen.findByText(/chat de comunicação rápida/i)).toBeInTheDocument();

        fireEvent.click(screen.getByLabelText('Fechar aviso'));

        await waitFor(() => expect(dispensarDicaChat).toHaveBeenCalled());
        await waitFor(() => expect(screen.queryByText(/chat de comunicação rápida/i)).toBeNull());
    });
});
