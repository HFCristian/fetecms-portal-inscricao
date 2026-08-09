import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));

const getReclassificacoes = vi.fn();
const aplicarReclassificacoes = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getReclassificacoes: (...a) => getReclassificacoes(...a),
    aplicarReclassificacoes: (...a) => aplicarReclassificacoes(...a),
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.response?.data?.message ?? 'Ocorreu um erro inesperado.' }),
}));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([
        { id: 1, nome: 'Exatas' },
        { id: 2, nome: 'Biológicas' },
    ])),
}));

import AvaliacaoReclassificacoes from './AvaliacaoReclassificacoes.jsx';

// Duas opções de área (Biológicas com 2 votos, Saúde com 1) para exercitar a escolha.
const AGUA = {
    projeto_id: 1, titulo: 'Purificação de água', area_id: 1, area: 'Exatas', subarea_id: null, subarea: null,
    total_sugestoes: 3,
    opcoes_area: [{ id: 2, nome: 'Biológicas', votos: 2 }, { id: 3, nome: 'Saúde', votos: 1 }],
    opcoes_subarea: [],
    area_mais_sugerida: { id: 2, nome: 'Biológicas', votos: 2 },
    subarea_mais_sugerida: null,
    sugestoes: [
        { avaliacao_id: 10, avaliador: 'Ana', avaliada_em: '07/08/2026 10:00', area_sugerida: 'Biológicas', subarea_sugerida: null },
        { avaliacao_id: 11, avaliador: 'Bruno', avaliada_em: '05/08/2026 10:00', area_sugerida: 'Biológicas', subarea_sugerida: null },
        { avaliacao_id: 13, avaliador: 'Diego', avaliada_em: '04/08/2026 10:00', area_sugerida: 'Saúde', subarea_sugerida: null },
    ],
};

// Só subárea sugerida — serve para checar que a linha de área não aparece.
const ABELHAS = {
    projeto_id: 2, titulo: 'Abelhas nativas', area_id: 2, area: 'Biológicas', subarea_id: 7, subarea: 'Botânica',
    total_sugestoes: 1,
    opcoes_area: [],
    opcoes_subarea: [{ id: 9, nome: 'Ecologia', votos: 1 }],
    area_mais_sugerida: null,
    subarea_mais_sugerida: { id: 9, nome: 'Ecologia', votos: 1 },
    sugestoes: [
        { avaliacao_id: 12, avaliador: 'Carla', avaliada_em: '06/08/2026 10:00', area_sugerida: null, subarea_sugerida: 'Ecologia' },
    ],
};

const ligarModoLote = () => fireEvent.click(screen.getByLabelText('Aceitar várias sugestões de uma vez'));

describe('AvaliacaoReclassificacoes', () => {
    beforeEach(() => {
        getReclassificacoes.mockReset();
        aplicarReclassificacoes.mockReset();
        getReclassificacoes.mockResolvedValue([AGUA, ABELHAS]);
        aplicarReclassificacoes.mockResolvedValue({ data: [], meta: { message: 'Reclassificação aplicada.' } });
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
        expect(screen.getByText(/projetos ·/)).toHaveTextContent('2 projetos · 4 sugestões');
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

    describe('aplicar uma sugestão', () => {
        it('aplica o consenso do projeto após confirmação e recarrega a lista', async () => {
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');

            // Um botão por projeto (os dois têm sugestão).
            const botoes = screen.getAllByRole('button', { name: /Aplicar sugestão/i });
            expect(botoes).toHaveLength(2);

            fireEvent.click(botoes[0]);
            // A confirmação mostra exatamente a troca que será feita.
            expect(await screen.findByText(/Área → Biológicas/)).toBeInTheDocument();
            fireEvent.click(screen.getByRole('button', { name: /^Aplicar$/i }));

            await waitFor(() => expect(aplicarReclassificacoes).toHaveBeenCalledWith([
                { projeto_id: 1, area_id: 2 },
            ]));
            // Recarrega para a sugestão aplicada sair da lista.
            await waitFor(() => expect(getReclassificacoes).toHaveBeenCalledTimes(2));
        });

        it('aplica a subárea quando é a única sugestão do projeto', async () => {
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Abelhas nativas');

            fireEvent.click(screen.getAllByRole('button', { name: /Aplicar sugestão/i })[1]);
            fireEvent.click(await screen.findByRole('button', { name: /^Aplicar$/i }));

            await waitFor(() => expect(aplicarReclassificacoes).toHaveBeenCalledWith([
                { projeto_id: 2, subarea_id: 9 },
            ]));
        });

        it('nada acontece se a confirmação for cancelada', async () => {
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');

            fireEvent.click(screen.getAllByRole('button', { name: /Aplicar sugestão/i })[0]);
            fireEvent.click(await screen.findByRole('button', { name: /Cancelar/i }));

            await waitFor(() => expect(aplicarReclassificacoes).not.toHaveBeenCalled());
        });

        it('só o botão acionado entra em carregamento; os outros ficam bloqueados', async () => {
            let liberar;
            aplicarReclassificacoes.mockReturnValue(new Promise((r) => { liberar = r; }));
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');

            fireEvent.click(screen.getAllByRole('button', { name: /Aplicar sugestão/i })[0]);
            fireEvent.click(await screen.findByRole('button', { name: /^Aplicar$/i }));
            await waitFor(() => expect(aplicarReclassificacoes).toHaveBeenCalled());

            const [primeiro, segundo] = screen.getAllByRole('button', { name: /Aplicar sugestão/i });
            // Ambos desabilitados enquanto roda, mas só o clicado gira.
            expect(primeiro).toBeDisabled();
            expect(segundo).toBeDisabled();
            expect(primeiro.querySelector('.animate-spin')).not.toBeNull();
            expect(segundo.querySelector('.animate-spin')).toBeNull();

            liberar({ data: [], meta: { message: 'Reclassificação aplicada.' } });
            await waitFor(() => expect(screen.getAllByRole('button', { name: /Aplicar sugestão/i })[1]).toBeEnabled());
        });

        it('mostra o erro devolvido pela API', async () => {
            aplicarReclassificacoes.mockRejectedValue({
                response: { data: { message: 'A área escolhida não consta nas sugestões.' } },
            });
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');

            fireEvent.click(screen.getAllByRole('button', { name: /Aplicar sugestão/i })[0]);
            fireEvent.click(await screen.findByRole('button', { name: /^Aplicar$/i }));

            expect(await screen.findByText('A área escolhida não consta nas sugestões.')).toBeInTheDocument();
        });
    });

    describe('modo de seleção múltipla', () => {
        it('troca o consenso pelas caixas de seleção de cada projeto', async () => {
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');

            expect(screen.getAllByRole('button', { name: /Aplicar sugestão/i })).toHaveLength(2);

            ligarModoLote();

            // Os botões individuais dão lugar às caixas de seleção.
            expect(screen.queryByRole('button', { name: /Aplicar sugestão/i })).not.toBeInTheDocument();
            expect(screen.getByLabelText('Selecionar todos')).toBeInTheDocument();
            // Água só tem sugestão de área; Abelhas só de subárea.
            expect(screen.getByLabelText('Área')).toBeInTheDocument();
            expect(screen.getByLabelText('Subárea')).toBeInTheDocument();
        });

        it('permite escolher qual sugestão aplicar quando há mais de uma opção', async () => {
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');
            ligarModoLote();

            const escolha = screen.getByLabelText('Área a aplicar em Purificação de água');
            // Desabilitado até marcar o projeto; já vem na opção mais votada.
            expect(escolha).toBeDisabled();
            expect(escolha).toHaveValue('2');

            fireEvent.click(screen.getByLabelText('Área'));
            expect(escolha).toBeEnabled();
            fireEvent.change(escolha, { target: { value: '3' } }); // troca para Saúde

            fireEvent.click(screen.getByRole('button', { name: /Aplicar selecionadas/i }));
            fireEvent.click(await screen.findByRole('button', { name: /^Aplicar$/i }));

            await waitFor(() => expect(aplicarReclassificacoes).toHaveBeenCalledWith([
                { projeto_id: 1, area_id: 3 },
            ]));
        });

        it('"Selecionar todos" marca área e subárea de todos os projetos', async () => {
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');
            ligarModoLote();

            fireEvent.click(screen.getByLabelText('Selecionar todos'));
            expect(screen.getByText('2 selecionado(s)')).toBeInTheDocument();

            fireEvent.click(screen.getByRole('button', { name: /Aplicar selecionadas/i }));
            fireEvent.click(await screen.findByRole('button', { name: /^Aplicar$/i }));

            await waitFor(() => expect(aplicarReclassificacoes).toHaveBeenCalledWith([
                { projeto_id: 1, area_id: 2 },
                { projeto_id: 2, subarea_id: 9 },
            ]));
        });

        it('aplica área e subárea juntas no mesmo projeto', async () => {
            getReclassificacoes.mockResolvedValue([{
                ...AGUA,
                opcoes_subarea: [{ id: 9, nome: 'Ecologia', votos: 1 }],
                subarea_mais_sugerida: { id: 9, nome: 'Ecologia', votos: 1 },
            }]);
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');
            ligarModoLote();

            fireEvent.click(screen.getByLabelText('Área'));
            fireEvent.click(screen.getByLabelText('Subárea'));

            fireEvent.click(screen.getByRole('button', { name: /Aplicar selecionadas/i }));
            fireEvent.click(await screen.findByRole('button', { name: /^Aplicar$/i }));

            await waitFor(() => expect(aplicarReclassificacoes).toHaveBeenCalledWith([
                { projeto_id: 1, area_id: 2, subarea_id: 9 },
            ]));
        });

        it('o botão de aplicar fica bloqueado enquanto nada estiver marcado', async () => {
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');
            ligarModoLote();

            const botao = screen.getByRole('button', { name: /Aplicar selecionadas/i });
            expect(botao).toBeDisabled();
            expect(screen.getByText('0 selecionado(s)')).toBeInTheDocument();

            fireEvent.click(screen.getByLabelText('Área'));
            expect(botao).toBeEnabled();
        });

        it('desmarcar "Selecionar todos" limpa a seleção', async () => {
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');
            ligarModoLote();

            const todos = screen.getByLabelText('Selecionar todos');
            fireEvent.click(todos);
            expect(todos).toBeChecked();

            fireEvent.click(todos);
            expect(screen.getByText('0 selecionado(s)')).toBeInTheDocument();
        });

        it('avisa quando a subárea é limpa por não pertencer à nova área', async () => {
            aplicarReclassificacoes.mockResolvedValue({
                data: [{ projeto_id: 1, subarea_limpa: true }],
                meta: { message: 'Reclassificação aplicada em 1 projeto.' },
            });
            render(<AvaliacaoReclassificacoes />);
            await screen.findByText('Purificação de água');

            fireEvent.click(screen.getAllByRole('button', { name: /Aplicar sugestão/i })[0]);
            fireEvent.click(await screen.findByRole('button', { name: /^Aplicar$/i }));

            expect(await screen.findByText(/A subárea de 1 projeto\(s\) foi limpa/)).toBeInTheDocument();
        });
    });
});
