import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getFeedbacks = vi.fn();
vi.mock('../lib/feedback.js', () => ({ getFeedbacks: (...a) => getFeedbacks(...a) }));

import AdminFeedbacks from './AdminFeedbacks.jsx';

describe('AdminFeedbacks', () => {
    beforeEach(() => getFeedbacks.mockReset());

    it('lista os pedidos com a razão respostas/convidados', async () => {
        getFeedbacks.mockResolvedValue([{
            id: 3, titulo: 'Como foi a feira?', descricao: null,
            status: 'ativo', status_label: 'No ar',
            perguntas: 2, convidados: 10, respostas: 6,
            publicos: ['Todos os orientadores'], autor: 'Ana Admin', criado_em: '30/08/2026 10:00',
        }]);
        render(<AdminFeedbacks />);

        expect(await screen.findByText('Como foi a feira?')).toBeInTheDocument();
        expect(screen.getByText('No ar')).toBeInTheDocument();
        expect(screen.getByText('6')).toBeInTheDocument();
        expect(screen.getByText('/10')).toBeInTheDocument();
        // A linha leva aos resultados daquele pedido.
        expect(screen.getByText('Como foi a feira?').closest('a'))
            .toHaveAttribute('href', '/admin/comunicacao/feedback/3');
    });

    it('explica a lista vazia e oferece a criação', async () => {
        getFeedbacks.mockResolvedValue([]);
        render(<AdminFeedbacks />);

        expect(await screen.findByText('Nenhum pedido de feedback criado nesta edição.')).toBeInTheDocument();
        expect(screen.getByText('Novo pedido de feedback').closest('a'))
            .toHaveAttribute('href', '/admin/comunicacao/feedback/novo');
    });
});
