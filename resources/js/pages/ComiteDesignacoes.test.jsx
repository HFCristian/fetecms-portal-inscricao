import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getOpcoesDesignacaoComite = vi.fn();
const designarPeloComite = vi.fn();
const getPermissoesComite = vi.fn();
const decidirPermissaoComite = vi.fn();
vi.mock('../lib/comite.js', () => ({
    getOpcoesDesignacaoComite: (...a) => getOpcoesDesignacaoComite(...a),
    designarPeloComite: (...a) => designarPeloComite(...a),
    getPermissoesComite: (...a) => getPermissoesComite(...a),
    decidirPermissaoComite: (...a) => decidirPermissaoComite(...a),
}));

import ComiteDesignacoes from './ComiteDesignacoes.jsx';

const OPCOES = {
    projetos: [{
        id: 1, titulo: 'Bioplástico', area: 'Exatas', categoria: 'FETECMS', concluidas: 0,
        codigo: 'FET.EXA-001', turno: 'A', turno_label: 'Turno A (matutino)', estande: 42,
    }],
    avaliadores: [{ id: 5, nome: 'Ana Comissao', email: 'ana@fetec.test', area: 'Exatas', na_fila: 2 }],
    turnos: [
        { value: 'A', label: 'Turno A (matutino)' },
        { value: 'B', label: 'Turno B (vespertino)' },
    ],
};

describe('ComiteDesignacoes', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getOpcoesDesignacaoComite.mockResolvedValue(OPCOES);
        getPermissoesComite.mockResolvedValue({ data: [], meta: { ativa: true, pendentes: 0 } });
        designarPeloComite.mockResolvedValue({
            data: { designadas: 1, ignoradas: [], resumo: '1 designação(ões) criada(s).' },
            meta: { message: '1 designação(ões) criada(s).' },
        });
    });

    it('só oferece avaliadores da comissão especial', async () => {
        render(<ComiteDesignacoes />);

        expect(await screen.findByText('Avaliadores da comissão especial')).toBeInTheDocument();
        // A busca é debounced: as listas chegam depois.
        expect(await screen.findByText('Ana Comissao')).toBeInTheDocument();
    });

    it('cruza os marcados e mostra o total antes de designar', async () => {
        render(<ComiteDesignacoes />);

        fireEvent.click(await screen.findByRole('checkbox', { name: 'Bioplástico' }));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Ana Comissao' }));

        expect(screen.getByText(/1 projeto\(s\) × 1 avaliador\(es\) =/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Designar/ }));

        await waitFor(() => expect(designarPeloComite).toHaveBeenCalledWith([1], [5]));
        expect(await screen.findByText('1 designação(ões) criada(s).')).toBeInTheDocument();
    });

    it('sem nada marcado não há o que designar', async () => {
        render(<ComiteDesignacoes />);

        await screen.findByText('Ana Comissao');
        expect(screen.getByRole('button', { name: /Designar/ })).toBeDisabled();
    });

    it('mostra quem ficou de fora e por quê', async () => {
        designarPeloComite.mockResolvedValue({
            data: {
                designadas: 0, resumo: '0 designação(ões) criada(s).',
                ignoradas: ['Bioplástico → Ana Comissao: já avaliou este projeto.'],
            },
            meta: {},
        });
        render(<ComiteDesignacoes />);

        fireEvent.click(await screen.findByRole('checkbox', { name: 'Bioplástico' }));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Ana Comissao' }));
        fireEvent.click(screen.getByRole('button', { name: /Designar/ }));

        expect(await screen.findByText(/já avaliou este projeto/)).toBeInTheDocument();
    });

    it('mostra o código, o estande e o turno de cada projeto', async () => {
        render(<ComiteDesignacoes />);

        expect(await screen.findByText(/FET\.EXA-001 · Estande 042 · Turno A \(matutino\)/)).toBeInTheDocument();
    });

    it('a busca e os filtros de código, estande e turno vão para o servidor', async () => {
        render(<ComiteDesignacoes />);
        await screen.findByText('Bioplástico');

        fireEvent.change(screen.getByLabelText('Buscar por título, código, estande ou turno'), { target: { value: 'vespertino' } });
        fireEvent.change(screen.getByLabelText('Código'), { target: { value: 'FET.AGR' } });
        fireEvent.change(screen.getByLabelText('Estande'), { target: { value: '04a2' } });
        fireEvent.change(screen.getByLabelText('Turno'), { target: { value: 'B' } });

        await waitFor(() => expect(getOpcoesDesignacaoComite).toHaveBeenLastCalledWith({
            projeto: 'vespertino', avaliador: '', codigo: 'FET.AGR', estande: '042', turno: 'B',
        }));
        expect(screen.getByText(/só aparecem os finalistas/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Limpar filtros' }));
        expect(screen.getByLabelText('Código')).toHaveValue('');
        expect(screen.getByLabelText('Turno')).toHaveValue('');
    });

    it('logo abaixo da designação, a organização decide os pedidos de permissão', async () => {
        const pedido = {
            id: 9, status: 'pendente', status_label: 'Aguardando a organização', justificativa: 'Sou da área.',
            resposta: null, pedido_em: '2026-10-20T09:00:00-04:00', decidido_em: null, decidido_por: null,
            avaliador: { id: 5, nome: 'Ana Comissao' },
            projeto: { id: 1, titulo: 'Bioplástico', codigo: 'FET.EXA-001', estande: 42, turno_label: 'Turno A (matutino)', area: 'Exatas', categoria: 'FETECMS' },
        };
        getPermissoesComite.mockResolvedValue({ data: [pedido], meta: { ativa: true, pendentes: 1 } });
        decidirPermissaoComite.mockResolvedValue({ data: { ...pedido, status: 'aprovado' }, meta: { message: 'Permissão aprovada.' } });
        render(<ComiteDesignacoes />);

        const secao = await screen.findByRole('region', { name: 'Pedidos de permissão' });
        expect(await within(secao).findByText('1 aguardando')).toBeInTheDocument();
        expect(within(secao).getByText(/FET\.EXA-001 · Estande 042/)).toBeInTheDocument();
        expect(within(secao).getByText('“Sou da área.”')).toBeInTheDocument();

        fireEvent.click(within(secao).getByRole('button', { name: 'Aprovar o pedido de Ana Comissao' }));
        await waitFor(() => expect(decidirPermissaoComite).toHaveBeenCalledWith(9, 'aprovar', null));
        expect(await within(secao).findByText('Permissão aprovada.')).toBeInTheDocument();
        expect(getPermissoesComite).toHaveBeenCalledTimes(2);
    });

    it('recusar pede a resposta opcional; com a regra desligada, a tela avisa', async () => {
        const pedido = {
            id: 9, status: 'pendente', status_label: 'Aguardando a organização', justificativa: null,
            resposta: null, pedido_em: null, decidido_em: null, decidido_por: null,
            avaliador: { id: 5, nome: 'Ana Comissao' },
            projeto: { id: 1, titulo: 'Bioplástico', codigo: null, estande: null, turno_label: null },
        };
        getPermissoesComite.mockResolvedValue({ data: [pedido], meta: { ativa: false, pendentes: 1 } });
        decidirPermissaoComite.mockResolvedValue({ data: {}, meta: { message: 'Pedido recusado.' } });
        render(<ComiteDesignacoes />);

        const secao = await screen.findByRole('region', { name: 'Pedidos de permissão' });
        expect(await within(secao).findByText(/A regra está desligada/)).toBeInTheDocument();

        fireEvent.click(within(secao).getByRole('button', { name: 'Recusar o pedido de Ana Comissao' }));
        fireEvent.change(within(secao).getByLabelText(/Resposta ao avaliador/), { target: { value: 'Já tem avaliador.' } });
        fireEvent.click(within(secao).getByRole('button', { name: 'Confirmar recusa' }));

        await waitFor(() => expect(decidirPermissaoComite).toHaveBeenCalledWith(9, 'recusar', 'Já tem avaliador.'));
    });

    it('sem ninguém na comissão, explica onde marcar', async () => {
        getOpcoesDesignacaoComite.mockResolvedValue({ ...OPCOES, avaliadores: [] });
        render(<ComiteDesignacoes />);

        expect(await screen.findByText(/Nenhum avaliador da comissão especial/)).toBeInTheDocument();
    });
});
