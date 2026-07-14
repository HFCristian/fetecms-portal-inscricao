import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/admin.js', () => ({
    getAvaliadores: vi.fn(() => Promise.resolve({
        total: 5,
        ativos: 4,
        inativos: 1,
        por_area: [
            { area_id: 1, area: 'Ciências Exatas', total: 3 },
            { area_id: 2, area: 'Ciências Biológicas', total: 2 },
        ],
    })),
}));

import AdminAvaliadores from './AdminAvaliadores.jsx';

describe('AdminAvaliadores — painel de avaliadores', () => {
    it('mostra os totais e a distribuição por área', async () => {
        render(<AdminAvaliadores />);
        expect(await screen.findByText('Ciências Exatas')).toBeInTheDocument();
        expect(screen.getByText('Ciências Biológicas')).toBeInTheDocument();
        expect(screen.getByText('Avaliadores por área')).toBeInTheDocument();
        expect(screen.getByText('5')).toBeInTheDocument(); // total
        expect(screen.getByText('Avaliadores (total)')).toBeInTheDocument();
    });
});
