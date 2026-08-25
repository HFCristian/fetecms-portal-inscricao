import { render, screen, fireEvent, waitFor } from '@testing-library/react';
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
const roletarFila = vi.fn();
const LISTA_ABERTA = {
    liberada: true, liberada_em: null, encerrada: false, pode_ver: true, pode_avaliar: true, is_demo: false,
    nota_maxima: 10, min_por_avaliador: 3,
    projetos: [
        { avaliacao_id: 1, projeto_id: 10, titulo: 'Projeto X', area: 'Ciências Exatas e da Terra', status: 'designada', status_label: 'Designada', nota: null },
    ],
    concluidos: [
        { avaliacao_id: 2, projeto_id: 11, titulo: 'Projeto Y', area: 'Ciências Exatas e da Terra', status: 'concluida', status_label: 'Concluída', nota: 8.5, concluida_em_label: '12/10/2026 09:30' },
    ],
};

vi.mock('../lib/avaliacao.js', () => ({
    getMinhaAvaliacao: vi.fn(() => Promise.resolve({
        liberada: true, liberada_em: null, encerrada: false, pode_ver: true, pode_avaliar: true, is_demo: false,
        nota_maxima: 10, min_por_avaliador: 3,
        projetos: [
            { avaliacao_id: 1, projeto_id: 10, titulo: 'Projeto X', area: 'Ciências Exatas e da Terra', status: 'designada', status_label: 'Designada', nota: null },
        ],
        concluidos: [
            { avaliacao_id: 2, projeto_id: 11, titulo: 'Projeto Y', area: 'Ciências Exatas e da Terra', status: 'concluida', status_label: 'Concluída', nota: 8.5, concluida_em_label: '12/10/2026 09:30' },
        ],
    })),
    getAvaliacao: vi.fn(() => Promise.resolve({
        avaliacao: { id: 1, status: 'designada', status_label: 'Designada', nota: null },
        projeto: { id: 10, titulo: 'Projeto X', resumo: 'Resumo do projeto', palavras_chave: [], alunos: [], documentos: [] },
    })),
    iniciarAvaliacao: vi.fn(),
    concluirAvaliacao: vi.fn(),
    roletarFila: (...a) => roletarFila(...a),
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

    it('separa os avaliados numa aba própria', async () => {
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);

        // A fila de trabalho não mistura o que já foi enviado.
        expect(await screen.findByText('Projeto X')).toBeInTheDocument();
        expect(screen.queryByText('Projeto Y')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('tab', { name: 'Avaliados (1)' }));

        expect(await screen.findByText('Projetos que você já avaliou')).toBeInTheDocument();
        expect(screen.getByText('Projeto Y')).toBeInTheDocument();
        expect(screen.queryByText('Projeto X')).not.toBeInTheDocument();
    });

    it('mostra a nota e a data na aba de avaliados', async () => {
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);
        fireEvent.click(await screen.findByRole('tab', { name: 'Avaliados (1)' }));

        expect(await screen.findByText('8,50')).toBeInTheDocument();
        expect(screen.getByText(/avaliado em 12\/10\/2026 09:30/)).toBeInTheDocument();
    });

    it('avisa quando ainda não há avaliação concluída', async () => {
        getMinhaAvaliacao.mockResolvedValueOnce({ ...LISTA_ABERTA, concluidos: [] });
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);

        fireEvent.click(await screen.findByRole('tab', { name: 'Avaliados (0)' }));

        expect(await screen.findByText('Você ainda não concluiu nenhuma avaliação.')).toBeInTheDocument();
    });

    it('sorteia outros projetos depois de confirmar', async () => {
        roletarFila.mockResolvedValue({ data: { trocados: 1, recebidos: 1 }, meta: { message: 'Fila sorteada de novo: 1 projeto(s) na sua lista.' } });
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);

        fireEvent.click(await screen.findByText('Sortear outros projetos'));
        fireEvent.click(await screen.findByRole('button', { name: 'Sortear' }));

        await waitFor(() => expect(roletarFila).toHaveBeenCalled());
        expect(await screen.findByText('Fila sorteada de novo: 1 projeto(s) na sua lista.')).toBeInTheDocument();
    });

    it('não oferece o sorteio com o período encerrado', async () => {
        getMinhaAvaliacao.mockResolvedValueOnce({ ...LISTA_ABERTA, encerrada: true, pode_avaliar: false });
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);

        await screen.findByText('Projeto X');
        expect(screen.queryByText('Sortear outros projetos')).not.toBeInTheDocument();
    });
});
