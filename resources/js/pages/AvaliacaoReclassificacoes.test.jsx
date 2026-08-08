import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));

const getReclassificacoes = vi.fn();
vi.mock('../lib/admin.js', () => ({ getReclassificacoes: (...a) => getReclassificacoes(...a) }));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([
        { id: 1, nome: 'Exatas' },
        { id: 2, nome: 'Biológicas' },
    ])),
}));

import AvaliacaoReclassificacoes from './AvaliacaoReclassificacoes.jsx';

const AGUA = {
    projeto_id: 1, titulo: 'Purificação de água', area_id: 1, area: 'Exatas', subarea: null,
    total_sugestoes: 2,
    area_mais_sugerida: { nome: 'Biológicas', votos: 2 },
    subarea_mais_sugerida: null,
    sugestoes: [
        { avaliacao_id: 10, avaliador: 'Ana', avaliada_em: '07/08/2026 10:00', area_sugerida: 'Biológicas', subarea_sugerida: null },
        { avaliacao_id: 11, avaliador: 'Bruno', avaliada_em: '05/08/2026 10:00', area_sugerida: 'Biológicas', subarea_sugerida: null },
    ],
};

const ABELHAS = {
    projeto_id: 2, titulo: 'Abelhas nativas', area_id: 2, area: 'Biológicas', subarea: 'Botânica',
    total_sugestoes: 1,
    area_mais_sugerida: null,
    subarea_mais_sugerida: { nome: 'Ecologia', votos: 1 },
    sugestoes: [
        { avaliacao_id: 12, avaliador: 'Carla', avaliada_em: '06/08/2026 10:00', area_sugerida: null, subarea_sugerida: 'Ecologia' },
    ],
};

describe('AvaliacaoReclassificacoes', () => {
    beforeEach(() => {
        getReclassificacoes.mockReset();
        getReclassificacoes.mockResolvedValue([AGUA, ABELHAS]);
    });

    it('lista os projetos com a classificação atual, o consenso e cada sugestão', async () => {
        render(<AvaliacaoReclassificacoes />);

        expect(await screen.findByText('Purificação de água')).toBeInTheDocument();
        expect(screen.getByText('Abelhas nativas')).toBeInTheDocument();
        // Consenso das duas naturezas (área e subárea).
        expect(screen.getByText(/Área:/)).toHaveTextContent('Biológicas');
        expect(screen.getByText(/Subárea:/)).toHaveTextContent('Ecologia');
        // Sugestões individuais, com avaliador e data.
        expect(screen.getByText('Ana')).toBeInTheDocument();
        expect(screen.getByText('07/08/2026 10:00')).toBeInTheDocument();
        // Totais no cabeçalho da lista (números vêm dentro de <strong>).
        expect(screen.getByText(/projetos ·/)).toHaveTextContent('2 projetos · 3 sugestões');
    });

    it('manda os filtros de nome, área e período para a API', async () => {
        render(<AvaliacaoReclassificacoes />);
        await screen.findByText('Purificação de água');

        fireEvent.change(screen.getByLabelText('Nome do projeto'), { target: { value: 'abelh' } });
        fireEvent.change(screen.getByLabelText('Área do conhecimento'), { target: { value: '2' } });
        fireEvent.change(screen.getByLabelText('Avaliado de'), { target: { value: '2026-08-01' } });
        fireEvent.change(screen.getByLabelText('Avaliado até'), { target: { value: '2026-08-20' } });
        fireEvent.click(screen.getByRole('button', { name: /Filtrar/i }));

        await waitFor(() => expect(getReclassificacoes).toHaveBeenCalledTimes(2));
        expect(getReclassificacoes).toHaveBeenLastCalledWith({
            q: 'abelh', area_id: '2', de: '2026-08-01', ate: '2026-08-20',
        });
    });

    it('o botão Limpar zera os filtros e recarrega sem eles', async () => {
        render(<AvaliacaoReclassificacoes />);
        await screen.findByText('Purificação de água');

        const busca = screen.getByLabelText('Nome do projeto');
        fireEvent.change(busca, { target: { value: 'abelh' } });
        fireEvent.click(screen.getByRole('button', { name: /Limpar/i }));

        await waitFor(() => expect(getReclassificacoes).toHaveBeenCalledTimes(2));
        expect(getReclassificacoes).toHaveBeenLastCalledWith({ area_id: '', q: '', de: '', ate: '' });
        expect(busca).toHaveValue('');
    });

    it('mostra estado vazio quando nenhum projeto tem sugestão', async () => {
        getReclassificacoes.mockResolvedValue([]);
        render(<AvaliacaoReclassificacoes />);

        expect(await screen.findByText(/Nenhum projeto com sugestão de reclassificação/)).toBeInTheDocument();
    });

    it('avisa quando a busca falha', async () => {
        getReclassificacoes.mockRejectedValue(new Error('falhou'));
        render(<AvaliacaoReclassificacoes />);

        expect(await screen.findByText('Não foi possível carregar as sugestões.')).toBeInTheDocument();
    });
});
