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
    /**
     * Numa tela larga mas baixa (notebook deitado, projetor 16:9) o admin tem
     * mais abas do que altura: sem rolagem, as últimas ficavam para fora da
     * janela e eram inalcançáveis. `min-h-0` é o que permite ao flex encolher o
     * filho abaixo do conteúdo dele — sem ele o `overflow-y-auto` não faz nada.
     */
    it('a lista de abas rola, e a saída da tela fica ancorada embaixo', () => {
        user.abas = undefined;
        const { container } = render(<AppShell><p>conteúdo</p></AppShell>);

        const lista = container.querySelector('nav > div.overflow-y-auto');
        expect(lista).not.toBeNull();
        expect(lista.className).toContain('min-h-0');
        expect(lista.className).toContain('flex-1');

        // Acesso e Sair moram fora da área que rola.
        expect(lista.textContent).not.toContain('Acesso');
        expect(container.querySelector('nav > div.shrink-0:last-child').textContent).toContain('Acesso');
    });

    it('a aba Cerimonial entra no menu como as demais', () => {
        user.abas = ['cerimonial'];
        render(<AppShell><p>conteúdo</p></AppShell>);

        expect(itens('Cerimonial').length).toBeGreaterThan(0);
        expect(itens('Credenciamento')).toHaveLength(0);
    });
});
