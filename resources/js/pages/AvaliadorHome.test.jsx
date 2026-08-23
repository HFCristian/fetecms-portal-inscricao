import { render, screen, fireEvent } from '@testing-library/react';
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
const LISTA_ABERTA = {
    liberada: true, liberada_em: null, encerrada: false, pode_ver: true, pode_avaliar: true, is_demo: false,
    projetos: [
        { avaliacao_id: 1, projeto_id: 10, titulo: 'Projeto X', area: 'Ciências Exatas e da Terra', status: 'designada', status_label: 'Designada', nota: null },
    ],
};

vi.mock('../lib/avaliacao.js', () => ({
    getMinhaAvaliacao: vi.fn(() => Promise.resolve({
        liberada: true, liberada_em: null, encerrada: false, pode_ver: true, pode_avaliar: true, is_demo: false,
        projetos: [
            { avaliacao_id: 1, projeto_id: 10, titulo: 'Projeto X', area: 'Ciências Exatas e da Terra', status: 'designada', status_label: 'Designada', nota: null },
        ],
    })),
    getAvaliacao: vi.fn(() => Promise.resolve({
        avaliacao: { id: 1, status: 'designada', status_label: 'Designada', nota: null },
        projeto: { id: 10, titulo: 'Projeto X', resumo: 'Resumo do projeto', palavras_chave: [], alunos: [], documentos: [] },
    })),
    iniciarAvaliacao: vi.fn(),
    concluirAvaliacao: vi.fn(),
}));

import { getMinhaAvaliacao } from '../lib/avaliacao.js';
import AvaliadorHome from './AvaliadorHome.jsx';

describe('AvaliadorHome', () => {
    it('lista os projetos designados', async () => {
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);
        expect(screen.getByText('Painel do Avaliador')).toBeInTheDocument();
        expect(await screen.findByText('Projeto X')).toBeInTheDocument();
        expect(screen.getByText('Projetos designados a você')).toBeInTheDocument();
    });

    it('abre o modal de avaliação ao clicar em Avaliar', async () => {
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);
        fireEvent.click(await screen.findByText('Avaliar'));
        expect(await screen.findByText('Iniciar avaliação')).toBeInTheDocument();
        expect(screen.getByText('Resumo do projeto')).toBeInTheDocument();
    });

    it('encerrado o período, lê os projetos mas não avalia', async () => {
        getMinhaAvaliacao.mockResolvedValueOnce({
            ...LISTA_ABERTA, encerrada: true, pode_avaliar: false, encerrada_em_label: '10/10/2026 18:00',
        });
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);

        expect(await screen.findByRole('alert')).toHaveTextContent(/período de avaliação foi encerrado/i);
        // A lista continua, mas o botão vira leitura.
        expect(screen.getByText('Projeto X')).toBeInTheDocument();
        expect(screen.getByText('Ver')).toBeInTheDocument();
        expect(screen.queryByText('Avaliar')).not.toBeInTheDocument();
    });

    it('sem liberação, não mostra a lista', async () => {
        getMinhaAvaliacao.mockResolvedValueOnce({
            liberada: false, encerrada: false, pode_ver: false, pode_avaliar: false, is_demo: false,
            liberada_em_label: '01/10/2026 08:00', projetos: [],
        });
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);

        expect(await screen.findByText('As avaliações ainda não foram liberadas')).toBeInTheDocument();
        expect(screen.queryByText('Projeto X')).not.toBeInTheDocument();
    });
});
