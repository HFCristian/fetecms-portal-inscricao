import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: vi.fn(() => Promise.resolve({
        liberada: true, encerrada: false,
        liberada_em_input: '2026-10-01T08:00', liberada_em_label: '01/10/2026 08:00',
        encerrada_em_input: '2026-10-10T18:00', encerrada_em_label: '10/10/2026 18:00',
        min_por_avaliador: 3, min_por_projeto: 3,
    })),
}));

import AdminAvaliacaoOnline from './AdminAvaliacaoOnline.jsx';

describe('AdminAvaliacaoOnline', () => {
    it('mostra a janela do período e os cards de acesso', async () => {
        render(<AdminAvaliacaoOnline />);
        // As datas são só um resumo: quem altera é a Parametrização.
        expect(await screen.findByText('Avaliação liberada — encerra em 10/10/2026 18:00.')).toBeInTheDocument();
        expect(screen.getByText('Alterar datas').closest('a')).toHaveAttribute('href', '/admin/parametrizacao/avaliacao');
        expect(screen.getByText('Avaliadores Online')).toBeInTheDocument();
        expect(screen.getByText('Projetos submetidos')).toBeInTheDocument();
    });

    it('leva ao algoritmo de distribuição por um botão', () => {
        render(<AdminAvaliacaoOnline />);

        expect(screen.getByRole('heading', { name: 'Algoritmo de distribuição' })).toBeInTheDocument();
        expect(screen.getByText('Abrir configurações').closest('a'))
            .toHaveAttribute('href', '/admin/avaliacao/distribuicao');
        // A configuração saiu daqui: nada de regras nem de botão de distribuir na landing.
        expect(screen.queryByRole('button', { name: /Distribuir avaliações/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    });
});
