import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

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

import PanoramaAvaliadores from './PanoramaAvaliadores.jsx';

describe('PanoramaAvaliadores', () => {
    it('mostra os totais e a distribuição por área', async () => {
        render(<PanoramaAvaliadores />);

        expect(await screen.findByText('Ciências Exatas')).toBeInTheDocument();
        expect(screen.getByText('Ciências Biológicas')).toBeInTheDocument();
        expect(screen.getByText('Distribuição por área')).toBeInTheDocument();
        expect(screen.getByText('Avaliadores (total)')).toBeInTheDocument();
        expect(screen.getByText('5')).toBeInTheDocument();
        expect(screen.getByText('Inativos')).toBeInTheDocument();
    });
});
