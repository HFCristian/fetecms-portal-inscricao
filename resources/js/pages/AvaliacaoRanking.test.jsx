import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));

const getRankingAvaliacao = vi.fn();
vi.mock('../lib/admin.js', () => ({ getRankingAvaliacao: (...a) => getRankingAvaliacao(...a) }));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([
        { id: 1, nome: 'Exatas' },
        { id: 2, nome: 'Biológicas' },
    ])),
}));

import AvaliacaoRanking from './AvaliacaoRanking.jsx';

const RANKING = [
    {
        projeto_id: 1, posicao: 1, titulo: 'Secador solar', area: 'Exatas', categoria: 'FETECMS FUNDECT',
        avaliacoes: 3, media: 29, nota_maxima: 30, completo: true,
        medias_quesitos: { video: 9.7, resumo: 9.7, pesquisa: 9.7 },
    },
    {
        projeto_id: 2, posicao: 2, titulo: 'Purificação de água', area: 'Exatas', categoria: 'FETECMS',
        avaliacoes: 3, media: 26.7, nota_maxima: 30, completo: true,
        medias_quesitos: { video: 8.7, resumo: 8.7, pesquisa: 9.3 },
    },
    {
        projeto_id: 3, posicao: 3, titulo: 'Aplicativo de triagem', area: 'Saúde', categoria: 'FETECMS',
        avaliacoes: 1, media: 19, nota_maxima: 30, completo: false,
        medias_quesitos: { video: 6, resumo: 7, pesquisa: 6 },
    },
];

describe('AvaliacaoRanking', () => {
    beforeEach(() => {
        getRankingAvaliacao.mockReset();
        getRankingAvaliacao.mockResolvedValue(RANKING);
    });

    it('lista os projetos na ordem recebida, com média sobre o total e nº de avaliações', async () => {
        render(<AvaliacaoRanking />);

        expect(await screen.findByText('Secador solar')).toBeInTheDocument();
        expect(screen.getByText('29')).toBeInTheDocument();
        expect(screen.getAllByText('/30')).toHaveLength(3);
        expect(screen.getAllByText('3 avaliações')).toHaveLength(2);

        // Ordem no DOM segue a classificação do backend.
        const linhas = screen.getAllByRole('listitem');
        expect(linhas[0]).toHaveTextContent('Secador solar');
        expect(linhas[2]).toHaveTextContent('Aplicativo de triagem');
    });

    it('mostra as médias de cada quesito', async () => {
        render(<AvaliacaoRanking />);
        await screen.findByText('Secador solar');

        expect(screen.getAllByText('Vídeo')).toHaveLength(3);
        expect(screen.getAllByText('Resumo')).toHaveLength(3);
        expect(screen.getAllByText('Pesquisa')).toHaveLength(3);
        expect(screen.getByText('9.3')).toBeInTheDocument();
    });

    it('sinaliza os projetos com média parcial', async () => {
        render(<AvaliacaoRanking />);
        await screen.findByText('Secador solar');

        // "parcial" aparece duas vezes: no selo da linha e destacado no aviso do topo.
        expect(screen.getAllByText('parcial')).toHaveLength(2);
        expect(screen.getByText(/ainda não atingiu/)).toHaveTextContent('1 projeto ainda não atingiu');
    });

    it('marca o pódio e numera do 4º em diante', async () => {
        getRankingAvaliacao.mockResolvedValue([
            ...RANKING,
            {
                projeto_id: 4, posicao: 4, titulo: 'Quarto colocado', area: 'Exatas', categoria: 'FETECMS',
                avaliacoes: 3, media: 15, nota_maxima: 30, completo: true,
                medias_quesitos: { video: 5, resumo: 5, pesquisa: 5 },
            },
        ]);
        render(<AvaliacaoRanking />);

        await screen.findByText('Quarto colocado');
        expect(screen.getByLabelText('1º lugar')).toHaveTextContent('🥇');
        expect(screen.getByLabelText('3º lugar')).toHaveTextContent('🥉');
        expect(screen.getByLabelText('4º lugar')).toHaveTextContent('4º');
    });

    it('filtra por área ao trocar o select', async () => {
        render(<AvaliacaoRanking />);
        await screen.findByText('Secador solar');

        fireEvent.change(screen.getByLabelText('Área do conhecimento'), { target: { value: '2' } });

        await waitFor(() => expect(getRankingAvaliacao).toHaveBeenCalledTimes(2));
        expect(getRankingAvaliacao).toHaveBeenLastCalledWith({ area_id: '2' });
    });

    it('mostra estado vazio quando ninguém foi avaliado', async () => {
        getRankingAvaliacao.mockResolvedValue([]);
        render(<AvaliacaoRanking />);

        expect(await screen.findByText('Nenhum projeto avaliado ainda.')).toBeInTheDocument();
    });
});
