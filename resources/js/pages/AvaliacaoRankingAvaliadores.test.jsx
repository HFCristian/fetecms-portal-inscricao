import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));

const getRankingAvaliadores = vi.fn();
vi.mock('../lib/admin.js', () => ({ getRankingAvaliadores: (...a) => getRankingAvaliadores(...a) }));

import AvaliacaoRankingAvaliadores from './AvaliacaoRankingAvaliadores.jsx';

const RANKING = [
    {
        posicao: 1, avaliador_id: 1, nome: 'Zilda Rocha', area: 'Ciências Exatas',
        concluidas: 5, em_avaliacao: 1, estado: 'MS', estado_nome: 'Mato Grosso do Sul', cidade: 'Campo Grande',
    },
    {
        posicao: 1, avaliador_id: 2, nome: 'Bruno Alves', area: 'Ciências Humanas',
        concluidas: 5, em_avaliacao: 0, estado: null, estado_nome: null, cidade: null,
    },
    {
        posicao: 3, avaliador_id: 3, nome: 'Ana Lima', area: 'Ciências Agrárias',
        concluidas: 2, em_avaliacao: 2, estado: 'SP', estado_nome: 'São Paulo', cidade: null,
    },
];

describe('AvaliacaoRankingAvaliadores', () => {
    beforeEach(() => {
        getRankingAvaliadores.mockReset();
        getRankingAvaliadores.mockResolvedValue(RANKING);
    });

    it('lista nome, área, localidade e os números de cada avaliador', async () => {
        render(<AvaliacaoRankingAvaliadores />);

        expect(await screen.findByText('Zilda Rocha')).toBeInTheDocument();
        expect(screen.getByText('Ciências Exatas')).toBeInTheDocument();
        expect(screen.getByText('Campo Grande/MS')).toBeInTheDocument();
        // Só o estado informado: mostra a UF sozinha.
        expect(screen.getByText('SP')).toBeInTheDocument();
        // Sem localidade nenhuma.
        expect(screen.getByText('—')).toBeInTheDocument();

        const linhas = screen.getAllByRole('row');
        expect(linhas[1]).toHaveTextContent('Zilda Rocha');
        expect(linhas[3]).toHaveTextContent('Ana Lima');
    });

    it('marca o pódio e divide a posição no empate', async () => {
        render(<AvaliacaoRankingAvaliadores />);
        await screen.findByText('Zilda Rocha');

        // Dois em 1º e o seguinte em 3º.
        expect(screen.getAllByLabelText('1º lugar')).toHaveLength(2);
        expect(screen.getByLabelText('3º lugar')).toHaveTextContent('🥉');
    });

    it('mostra estado vazio quando ninguém concluiu avaliação', async () => {
        getRankingAvaliadores.mockResolvedValue([]);
        render(<AvaliacaoRankingAvaliadores />);

        expect(await screen.findByText('Nenhuma avaliação concluída ainda.')).toBeInTheDocument();
    });
});
