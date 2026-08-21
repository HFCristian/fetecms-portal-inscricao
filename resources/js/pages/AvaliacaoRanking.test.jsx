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
        avaliacoes: 3, media: 14.5, nota_maxima: 15, completo: true,
        medias_quesitos: { video: 5, resumo: 5, pesquisa: 4.5, continuidade: null },
    },
    {
        projeto_id: 2, posicao: 2, titulo: 'Purificação de água', area: 'Exatas', categoria: 'FETECMS',
        avaliacoes: 3, media: 12.7, nota_maxima: 15, completo: true,
        medias_quesitos: { video: 4.3, resumo: 4.3, pesquisa: 4.7, continuidade: null },
    },
    {
        projeto_id: 3, posicao: 3, titulo: 'Aplicativo de triagem', area: 'Saúde', categoria: 'FETECMS',
        avaliacoes: 1, media: 9, nota_maxima: 15, completo: false,
        medias_quesitos: { video: 3, resumo: 3, pesquisa: 3, continuidade: null },
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
        expect(screen.getByText('14,5')).toBeInTheDocument();
        expect(screen.getAllByText('/15')).toHaveLength(3);
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
        expect(screen.getByText('4,7')).toBeInTheDocument();
        // Nenhum destes tem projeto de continuação.
        expect(screen.queryByText('Continuação')).not.toBeInTheDocument();
    });

    it('mostra a média da continuação só para quem tem esse quesito', async () => {
        getRankingAvaliacao.mockResolvedValue([
            {
                ...RANKING[0],
                medias_quesitos: { video: 5, resumo: 5, pesquisa: 4.5, continuidade: 4 },
            },
            RANKING[1],
        ]);
        render(<AvaliacaoRanking />);

        await screen.findByText('Secador solar');
        expect(screen.getAllByText('Continuação')).toHaveLength(1);
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
                avaliacoes: 3, media: 8, nota_maxima: 15, completo: true,
                medias_quesitos: { video: 3, resumo: 2.5, pesquisa: 2.5, continuidade: null },
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
