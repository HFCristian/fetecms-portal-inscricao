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
const retomarAvaliacao = vi.fn();
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
    retomarAvaliacao: (...a) => retomarAvaliacao(...a),
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

    it('busca entre os projetos já avaliados', async () => {
        getMinhaAvaliacao.mockResolvedValueOnce({
            ...LISTA_ABERTA,
            concluidos: [
                { avaliacao_id: 2, projeto_id: 11, titulo: 'Horta vertical', area: 'Ciências Agrárias', status: 'concluida', status_label: 'Concluída', nota: 8.5, concluida_em_label: '12/10/2026 09:30' },
                { avaliacao_id: 3, projeto_id: 12, titulo: 'Ponte de palito', area: 'Ciências Exatas e da Terra', status: 'concluida', status_label: 'Concluída', nota: 7, concluida_em_label: '13/10/2026 10:00' },
            ],
        });
        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);
        fireEvent.click(await screen.findByRole('tab', { name: 'Avaliados (2)' }));

        const busca = await screen.findByLabelText('Buscar entre os projetos que você já avaliou');
        fireEvent.change(busca, { target: { value: 'horta' } });

        expect(screen.getByText('Horta vertical')).toBeInTheDocument();
        expect(screen.queryByText('Ponte de palito')).not.toBeInTheDocument();

        // A busca alcança também a área do conhecimento.
        fireEvent.change(busca, { target: { value: 'exatas' } });
        expect(screen.getByText('Ponte de palito')).toBeInTheDocument();
        expect(screen.queryByText('Horta vertical')).not.toBeInTheDocument();

        fireEvent.change(busca, { target: { value: 'nada disso' } });
        expect(screen.getByText('Nenhuma avaliação sua corresponde a essa busca.')).toBeInTheDocument();
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

    // Sprint 112: o que o prazo devolveu ao bolo aparece numa seção própria, com
    // o rascunho guardado e o botão de retomar.
    it('lista as avaliações devolvidas pelo prazo e retoma uma', async () => {
        getMinhaAvaliacao.mockResolvedValue({
            ...LISTA_ABERTA,
            devolvidos: [{
                avaliacao_id: 9, projeto_id: 30, titulo: 'Projeto Devolvido',
                area: 'Ciências Exatas e da Terra', status: 'em_andamento',
                status_label: 'Em andamento', nota: null, devolvida_em_label: '05/09/2026 10:00',
            }],
        });
        retomarAvaliacao.mockResolvedValue({ data: {}, meta: { message: 'Avaliação retomada de onde você parou.' } });

        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);

        expect(await screen.findByText('Avaliações devolvidas')).toBeInTheDocument();
        expect(screen.getByText('Projeto Devolvido')).toBeInTheDocument();
        expect(screen.getByText(/devolvida em 05\/09\/2026 10:00/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Retomar' }));

        await waitFor(() => expect(retomarAvaliacao).toHaveBeenCalledWith(9, false));
    });

    it('sem devolvidas, a seção não aparece', async () => {
        getMinhaAvaliacao.mockResolvedValue({ ...LISTA_ABERTA, devolvidos: [] });

        render(<MemoryRouter><AvaliadorHome /></MemoryRouter>);

        expect(await screen.findByText('Projeto X')).toBeInTheDocument();
        expect(screen.queryByText('Avaliações devolvidas')).not.toBeInTheDocument();
    });
});
