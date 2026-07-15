import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: vi.fn(() => Promise.resolve({ liberada: false, liberada_em: null })),
    definirLiberacaoAvaliacao: vi.fn(() => Promise.resolve({ liberada: false, liberada_em: null })),
}));

import AdminAvaliacaoOnline from './AdminAvaliacaoOnline.jsx';

describe('AdminAvaliacaoOnline', () => {
    it('mostra a seção de liberação e os cards de acesso', async () => {
        render(<AdminAvaliacaoOnline />);
        expect(screen.getByText('Liberação da avaliação')).toBeInTheDocument();
        expect(await screen.findByText('Sem data definida')).toBeInTheDocument();
        expect(screen.getByText('Avaliadores por área')).toBeInTheDocument();
        expect(screen.getByText('Projetos submetidos')).toBeInTheDocument();
    });
});
