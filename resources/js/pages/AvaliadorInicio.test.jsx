import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));

const mockUser = { name: 'Ana Souza', role: 'avaliador', evento_em_andamento: false, avaliador_profile: {} };
vi.mock('../lib/auth.jsx', () => ({ useAuth: () => ({ user: mockUser }) }));

import AvaliadorInicio from './AvaliadorInicio.jsx';

const entrar = () => render(<MemoryRouter><AvaliadorInicio /></MemoryRouter>);
// O nome da aba é o primeiro texto do título (o selo "Evento em andamento" vem depois).
const titulos = () => screen.getAllByRole('heading', { level: 2 }).map((h) => h.firstChild.textContent);

/** Sprint 246: /avaliador é uma Home com um botão por aba, como a do admin. */
describe('AvaliadorInicio', () => {
    beforeEach(() => {
        mockUser.evento_em_andamento = false;
        mockUser.avaliador_profile = { presencial: null, apenas_presencial: false, comissao_especial: false };
    });

    it('cumprimenta e mostra um botão por aba, na ordem do menu', () => {
        entrar();

        expect(screen.getByText('Olá, Ana')).toBeInTheDocument();
        expect(titulos()).toEqual(['Avaliação online', 'Avaliação presencial', 'Perfil']);
        expect(screen.getByRole('link', { name: /Avaliação online/ })).toHaveAttribute('href', '/avaliador/avaliacao-online');
        expect(screen.getByRole('link', { name: /Avaliação presencial/ })).toHaveAttribute('href', '/avaliador/avaliacao-presencial');
    });

    it('a comissão especial vê também o Comitê especial', () => {
        mockUser.avaliador_profile.comissao_especial = true;
        entrar();

        expect(titulos()).toEqual(['Avaliação online', 'Avaliação presencial', 'Comitê especial', 'Perfil']);
    });

    it('durante o evento, quem vai ao estande encontra a avaliação presencial primeiro e destacada', () => {
        mockUser.evento_em_andamento = true;
        mockUser.avaliador_profile.presencial = true;
        entrar();

        expect(titulos()[0]).toBe('Avaliação presencial');
        expect(screen.getByText('Evento em andamento')).toBeInTheDocument();
    });

    it('durante o evento, quem não vai ao estande segue na ordem de sempre', () => {
        mockUser.evento_em_andamento = true;
        mockUser.avaliador_profile.presencial = false;
        entrar();

        expect(titulos()[0]).toBe('Avaliação online');
        expect(screen.queryByText('Evento em andamento')).not.toBeInTheDocument();
    });

    it('a conta só do presencial não tem avaliação online e abre pela do estande', () => {
        mockUser.avaliador_profile = { presencial: true, apenas_presencial: true, comissao_especial: false };
        entrar();

        expect(titulos()).toEqual(['Avaliação presencial', 'Perfil']);
    });
});
