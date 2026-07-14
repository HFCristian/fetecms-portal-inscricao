import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({
        user: { name: 'Av Teste', role: 'avaliador', avaliador_profile: { area: 'Ciências Exatas e da Terra', subarea: null } },
        logout: vi.fn(),
        setUser: vi.fn(),
    }),
    homeFor: () => '/avaliador',
}));

import AvaliadorHome from './AvaliadorHome.jsx';

describe('AvaliadorHome', () => {
    it('renderiza o painel do avaliador sem erro', () => {
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);
        expect(screen.getByText('Painel do Avaliador')).toBeInTheDocument();
    });
});
