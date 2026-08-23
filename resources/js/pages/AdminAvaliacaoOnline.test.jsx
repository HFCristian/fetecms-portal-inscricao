import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: vi.fn(() => Promise.resolve({
        liberada: true, encerrada: false,
        liberada_em_input: '2026-10-01T08:00', liberada_em_label: '01/10/2026 08:00',
        encerrada_em_input: '2026-10-10T18:00', encerrada_em_label: '10/10/2026 18:00',
    })),
    distribuirAvaliacoes: vi.fn(() => Promise.resolve({ data: { designadas_criadas: 0, sub_cobertos: [] }, meta: { message: '0 designações' } })),
}));

import AdminAvaliacaoOnline from './AdminAvaliacaoOnline.jsx';

describe('AdminAvaliacaoOnline', () => {
    it('mostra a janela do período, a distribuição e os cards de acesso', async () => {
        render(<AdminAvaliacaoOnline />);
        expect(screen.getByText('Distribuição automática')).toBeInTheDocument();
        expect(screen.getByText('Distribuir avaliações')).toBeInTheDocument();
        // As datas são só um resumo: quem altera é a Parametrização.
        expect(await screen.findByText('Avaliação liberada — encerra em 10/10/2026 18:00.')).toBeInTheDocument();
        expect(screen.getByText('Alterar datas').closest('a')).toHaveAttribute('href', '/admin/parametrizacao/avaliacao');
        expect(screen.getByText('Avaliadores Online')).toBeInTheDocument();
        expect(screen.getByText('Projetos submetidos')).toBeInTheDocument();
    });
});
