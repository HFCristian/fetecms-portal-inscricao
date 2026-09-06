import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

// As duas ações agora só ENFILEIRAM: respondem com o registro da rodada, e a
// tela acompanha o progresso por polling até `finalizada`.
const naFila = (tipo, id = 7) => ({
    data: {
        id, tipo, status: 'pendente', status_label: 'Na fila', finalizada: false,
        total: 0, processados: 0, percentual: 0, etapa: null, relatorio: null, erro: null,
    },
    meta: { message: 'Distribuição na fila. Acompanhe o progresso abaixo.' },
});

const concluida = (relatorio) => ({
    id: 7, tipo: 'redistribuir', status: 'concluida', status_label: 'Concluída',
    finalizada: true, total: 4, processados: 4, percentual: 100,
    etapa: null, relatorio, erro: null,
});

const distribuirAvaliacoes = vi.fn(() => Promise.resolve(naFila('distribuir')));
const redistribuirAvaliacoes = vi.fn(() => Promise.resolve(naFila('redistribuir')));
const definirPisoFila = vi.fn();
const definirDesignacoesPorProjeto = vi.fn();
const getProgressoDistribuicao = vi.fn();
const getUltimaDistribuicao = vi.fn(() => Promise.resolve(null));

vi.mock('../lib/admin.js', () => ({
    getAvaliacaoConfig: vi.fn(() => Promise.resolve({
        liberada: true, encerrada: false,
        liberada_em_label: '01/10/2026 08:00', encerrada_em_label: null,
        min_por_avaliador: 3, min_por_projeto: 3,
    })),
    getDistribuicaoConfig: vi.fn(() => Promise.resolve({
        regras: {
            fetec_jr: { ativa: true, min_concluidas: 0, max_concluidas: null },
            fetecms: { ativa: true, min_concluidas: 0, max_concluidas: null },
            fetecms_fundect: { ativa: true, min_concluidas: 0, max_concluidas: null },
        },
        categorias: [
            { value: 'fetec_jr', label: 'FETEC Jr' },
            { value: 'fetecms', label: 'FETECMS' },
            { value: 'fetecms_fundect', label: 'FETECMS FUNDECT' },
        ],
        max_concluidas: 50,
        ao_cadastrar: false,
        piso_fila: 6,
        piso_maximo: 50,
        designacoes_por_projeto: null,
        designacoes_maximo: 50,
        designacoes_categorias: [
            { value: 'fetec_jr', label: 'FETEC Jr', min_efetivo: 3, max_efetivo: 5, designacoes: null, designacoes_efetivo: 5 },
            { value: 'fetecms', label: 'FETECMS', min_efetivo: 3, max_efetivo: 5, designacoes: null, designacoes_efetivo: 5 },
            { value: 'fetecms_fundect', label: 'FETECMS FUNDECT', min_efetivo: 3, max_efetivo: 5, designacoes: null, designacoes_efetivo: 5 },
        ],
    })),
    definirRegrasDistribuicao: vi.fn(),
    definirPisoFila: (...a) => definirPisoFila(...a),
    definirDesignacoesPorProjeto: (...a) => definirDesignacoesPorProjeto(...a),
    definirDistribuicaoAoCadastrar: vi.fn(),
    distribuirAvaliacoes: (...a) => distribuirAvaliacoes(...a),
    redistribuirAvaliacoes: (...a) => redistribuirAvaliacoes(...a),
    getProgressoDistribuicao: (...a) => getProgressoDistribuicao(...a),
    getUltimaDistribuicao: (...a) => getUltimaDistribuicao(...a),
}));

import AvaliacaoDistribuicao from './AvaliacaoDistribuicao.jsx';

describe('AvaliacaoDistribuicao', () => {
    beforeEach(() => {
        vi.useRealTimers();
        distribuirAvaliacoes.mockClear();
        redistribuirAvaliacoes.mockClear();
        getProgressoDistribuicao.mockReset();
        getUltimaDistribuicao.mockReset().mockResolvedValue(null);
        definirPisoFila.mockReset();
    });

    it('reúne regras, toggle e as duas ações de distribuição', async () => {
        render(<AvaliacaoDistribuicao />);

        // Uma regra por categoria.
        expect(await screen.findByRole('switch', { name: /FETEC Jr/ })).toBeInTheDocument();
        expect(screen.getByLabelText('Máximo de avaliações recebidas — FETECMS')).toHaveValue(null);
        expect(screen.getByRole('switch', { name: 'Designar projetos ao cadastrar um avaliador' }))
            .toHaveAttribute('aria-checked', 'false');
        expect(screen.getByRole('button', { name: /Distribuir avaliações/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Redistribuir avaliações/ })).toBeInTheDocument();
        // Volta para a landing da aba.
        expect(screen.getByText('Avaliação online').closest('a')).toHaveAttribute('href', '/admin/avaliacao');
    });

    it('redistribui: enfileira, mostra a barra e conclui com o relatório', async () => {
        getProgressoDistribuicao
            .mockResolvedValueOnce({
                id: 7, tipo: 'redistribuir', status: 'processando', status_label: 'Distribuindo',
                finalizada: false, total: 4, processados: 2, percentual: 50,
                etapa: 'Trocando as designações não abertas', relatorio: null, erro: null,
            })
            .mockResolvedValue(concluida({
                devolvidas: 2, recebidas: 2, designadas_criadas: 2,
                ignorados_pela_regra: 0, sub_cobertos: [],
            }));

        render(<AvaliacaoDistribuicao />);

        fireEvent.click(await screen.findByRole('button', { name: /Redistribuir avaliações/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Redistribuir' }));

        await waitFor(() => expect(redistribuirAvaliacoes).toHaveBeenCalled());

        // A barra aparece com a etapa e a fração da rodada (a 1ª consulta só
        // acontece depois do intervalo de polling).
        expect(await screen.findByText('Trocando as designações não abertas', {}, { timeout: 4000 }))
            .toBeInTheDocument();
        expect(screen.getByText('2 de 4 · 50%')).toBeInTheDocument();
        expect(screen.getByRole('progressbar', { name: 'Progresso da distribuição' }))
            .toHaveAttribute('aria-valuenow', '50');

        // E o relatório só aparece quando termina.
        expect(await screen.findByText(/2 designação\(ões\) devolvidas ao bolo/, {}, { timeout: 4000 }))
            .toBeInTheDocument();
        expect(screen.getByText('Todos os projetos elegíveis têm ao menos 3 avaliadores.')).toBeInTheDocument();
    }, 10000);

    it('enquanto a rodada corre, os dois botões ficam travados', async () => {
        getProgressoDistribuicao.mockResolvedValue({
            id: 7, tipo: 'distribuir', status: 'processando', status_label: 'Distribuindo',
            finalizada: false, total: 10, processados: 3, percentual: 30,
            etapa: 'Designando os projetos', relatorio: null, erro: null,
        });

        render(<AvaliacaoDistribuicao />);
        fireEvent.click(await screen.findByRole('button', { name: /Distribuir avaliações/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Distribuir' }));

        await waitFor(() => expect(distribuirAvaliacoes).toHaveBeenCalled());

        expect(await screen.findByRole('button', { name: /Redistribuir avaliações/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Distribuir avaliações/ })).toBeDisabled();
        expect(screen.getByText(/Pode fechar esta página/)).toBeInTheDocument();
    }, 10000);

    // A rodada roda no servidor: reabrir a tela precisa reencontrá-la.
    it('ao abrir, retoma o acompanhamento de uma rodada em andamento', async () => {
        getUltimaDistribuicao.mockResolvedValue({
            id: 9, tipo: 'distribuir', status: 'processando', status_label: 'Distribuindo',
            finalizada: false, total: 8, processados: 6, percentual: 75,
            etapa: 'Designando os projetos', relatorio: null, erro: null,
        });
        getProgressoDistribuicao.mockResolvedValue(concluida({
            designadas_criadas: 8, ignorados_pela_regra: 0, sub_cobertos: [],
        }));

        render(<AvaliacaoDistribuicao />);

        expect(await screen.findByText('6 de 8 · 75%')).toBeInTheDocument();
        await waitFor(() => expect(getProgressoDistribuicao).toHaveBeenCalledWith(9), { timeout: 4000 });
    }, 10000);

    it('mostra o motivo quando a rodada falha', async () => {
        getProgressoDistribuicao.mockResolvedValue({
            id: 7, tipo: 'distribuir', status: 'falha', status_label: 'Falhou',
            finalizada: true, total: 4, processados: 1, percentual: 25,
            etapa: null, relatorio: null, erro: 'banco fora do ar',
        });

        render(<AvaliacaoDistribuicao />);
        fireEvent.click(await screen.findByRole('button', { name: /Distribuir avaliações/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Distribuir' }));

        expect(await screen.findByText('banco fora do ar', {}, { timeout: 4000 })).toBeInTheDocument();
        // Destravou: dá para tentar de novo.
        expect(screen.getByRole('button', { name: /Distribuir avaliações/ })).not.toBeDisabled();
    }, 10000);
});

// O piso é a rede das regras por categoria: elas escolhem quem entra primeiro,
// ele só evita que alguém termine a distribuição com a fila quase vazia.
describe('AvaliacaoDistribuicao — piso da fila', () => {
    beforeEach(() => {
        getUltimaDistribuicao.mockReset().mockResolvedValue(null);
        definirPisoFila.mockReset();
    });

    it('mostra o piso em vigor', async () => {
        render(<AvaliacaoDistribuicao />);

        expect(await screen.findByLabelText('Piso da fila do avaliador')).toHaveValue(6);
    });

    it('salva um piso novo', async () => {
        definirPisoFila.mockResolvedValue({
            data: { piso_fila: 4, piso_maximo: 50, regras: {}, categorias: [], ao_cadastrar: false },
            meta: { message: 'Piso da fila atualizado.' },
        });
        render(<AvaliacaoDistribuicao />);

        fireEvent.change(await screen.findByLabelText('Piso da fila do avaliador'), { target: { value: '4' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar piso' }));

        await waitFor(() => expect(definirPisoFila).toHaveBeenCalledWith(4));
        expect(await screen.findByText('Piso da fila atualizado.')).toBeInTheDocument();
    });

    it('remover o piso manda null — aí a regra manda sozinha', async () => {
        definirPisoFila.mockResolvedValue({
            data: { piso_fila: null, piso_maximo: 50, regras: {}, categorias: [], ao_cadastrar: false },
            meta: { message: 'Piso da fila atualizado.' },
        });
        render(<AvaliacaoDistribuicao />);

        fireEvent.click(await screen.findByRole('button', { name: 'Remover piso' }));

        await waitFor(() => expect(definirPisoFila).toHaveBeenCalledWith(null));
        expect(await screen.findByLabelText('Piso da fila do avaliador')).toHaveValue(null);
    });

    it('salva quantas designações a distribuição cria por projeto', async () => {
        definirDesignacoesPorProjeto.mockResolvedValue({
            data: {
                regras: {}, categorias: [], max_concluidas: 50, ao_cadastrar: false,
                piso_fila: 6, piso_maximo: 50,
                designacoes_por_projeto: 5, designacoes_maximo: 50,
                designacoes_categorias: [
                    { value: 'fetecms', label: 'FETECMS', min_efetivo: 3, max_efetivo: 3, designacoes: null, designacoes_efetivo: 5 },
                ],
            },
            meta: { message: 'Designações por projeto atualizadas.' },
        });
        render(<AvaliacaoDistribuicao />);

        const campo = await screen.findByLabelText('Designações por projeto');
        // Em branco, a tela diz que o alvo segue o mínimo.
        expect(campo).toHaveValue(null);

        fireEvent.change(campo, { target: { value: '5' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar designações' }));

        await waitFor(() => expect(definirDesignacoesPorProjeto).toHaveBeenCalledWith(5));
        // A tela passa a explicar quantos ficam de reserva além do que o
        // projeto aceita.
        expect(await screen.findByText(/2 ficam de reserva/)).toBeInTheDocument();
    });

    it('voltar ao mínimo manda null', async () => {
        definirDesignacoesPorProjeto.mockResolvedValue({
            data: {
                regras: {}, categorias: [], max_concluidas: 50, ao_cadastrar: false,
                piso_fila: 6, piso_maximo: 50,
                designacoes_por_projeto: null, designacoes_maximo: 50, designacoes_categorias: [],
            },
            meta: { message: 'Designações por projeto atualizadas.' },
        });
        render(<AvaliacaoDistribuicao />);
        await screen.findByLabelText('Designações por projeto');

        // Com o valor em branco no servidor não há o que remover.
        expect(screen.queryByRole('button', { name: 'Voltar ao máximo' })).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Designações por projeto'), { target: { value: '4' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar designações' }));

        await waitFor(() => expect(definirDesignacoesPorProjeto).toHaveBeenCalledWith(4));
    });
});
