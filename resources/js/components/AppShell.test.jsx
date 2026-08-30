import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

const user = { id: 1, name: 'Admin', role: 'admin', abas: [] };
vi.mock('../lib/auth.jsx', () => ({ useAuth: () => ({ user, logout: vi.fn() }) }));
vi.mock('../lib/chat.js', () => ({ getConversasNaoVistas: () => Promise.resolve({ total: 0 }) }));
vi.mock('./SeletorEdicao.jsx', () => ({ default: () => null }));
vi.mock('./AvisoCard.jsx', () => ({ default: () => null }));
vi.mock('./ChatWidget.jsx', () => ({ default: () => null }));
vi.mock('./SupportFooter.jsx', () => ({ default: () => null }));
vi.mock('react-router-dom', () => ({
    NavLink: ({ children, to }) => <a href={to}>{children}</a>,
    useNavigate: () => vi.fn(),
}));

import AppShell from './AppShell.jsx';

// O menu é renderizado duas vezes (sidebar do desktop + menu mobile).
const itens = (nome) => screen.queryAllByText(nome);

describe('AppShell — menu do admin por escopo', () => {
    it('mostra todas as abas quando o escopo não restringe', () => {
        user.abas = ['projetos', 'avaliacao', 'comunicacao', 'suporte', 'parametrizacao', 'administradores', 'registros'];
        render(<AppShell><p>conteúdo</p></AppShell>);

        expect(itens('Projetos').length).toBeGreaterThan(0);
        expect(itens('Comunicação').length).toBeGreaterThan(0);
        expect(itens('Registros').length).toBeGreaterThan(0);
    });

    it('esconde as abas fora do escopo', () => {
        user.abas = ['projetos', 'registros'];
        render(<AppShell><p>conteúdo</p></AppShell>);

        expect(itens('Projetos').length).toBeGreaterThan(0);
        expect(itens('Registros').length).toBeGreaterThan(0);
        expect(itens('Comunicação')).toHaveLength(0);
        expect(itens('Suporte')).toHaveLength(0);
        expect(itens('Administradores')).toHaveLength(0);
    });

    it('sem lista de abas no payload, mostra tudo (o backend é quem barra)', () => {
        user.abas = undefined;
        render(<AppShell><p>conteúdo</p></AppShell>);

        expect(itens('Parametrização').length).toBeGreaterThan(0);
        expect(itens('Administradores').length).toBeGreaterThan(0);
    });
});
