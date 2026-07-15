import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: vi.fn(() => Promise.resolve({ liberada: false, liberada_em_input: null, liberada_em_label: null })),
    definirLiberacaoAvaliacao: vi.fn(() => Promise.resolve({ liberada: false, liberada_em_input: null, liberada_em_label: null })),
    distribuirAvaliacoes: vi.fn(() => Promise.resolve({ data: { designadas_criadas: 0, sub_cobertos: [] }, meta: { message: '0 designações' } })),
}));

import AdminAvaliacaoOnline from './AdminAvaliacaoOnline.jsx';

describe('AdminAvaliacaoOnline', () => {
    it('mostra liberação, distribuição e cards de acesso', async () => {
        render(<AdminAvaliacaoOnline />);
        expect(screen.getByText('Liberação da avaliação')).toBeInTheDocument();
        expect(screen.getByText('Distribuição automática')).toBeInTheDocument();
        expect(screen.getByText('Distribuir avaliações')).toBeInTheDocument();
        expect(await screen.findByText('Sem data definida')).toBeInTheDocument();
        expect(screen.getByText('Avaliadores por área')).toBeInTheDocument();
        expect(screen.getByText('Projetos submetidos')).toBeInTheDocument();
    });
});
