import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getPremiacaoComite = vi.fn();
const premiarProjeto = vi.fn();
const retirarPremiacao = vi.fn();
const baixarPremiacaoComite = vi.fn();
vi.mock('../lib/comite.js', () => ({
    getPremiacaoComite: (...a) => getPremiacaoComite(...a),
    premiarProjeto: (...a) => premiarProjeto(...a),
    retirarPremiacao: (...a) => retirarPremiacao(...a),
    baixarPremiacaoComite: (...a) => baixarPremiacaoComite(...a),
}));

import ComitePremiacao from './ComitePremiacao.jsx';

const CANDIDATOS = [
    { id: 7, titulo: 'Bioplástico', categoria: 'FETECMS', area: 'Agrárias', escola: 'EE Alfa', credenciais: [] },
    { id: 8, titulo: 'Abelhas nativas', categoria: 'FETEC Jr', area: 'Biológicas', escola: 'EE Beta', credenciais: ['Destaque'] },
];

const MOSTRATEC = {
    id: 1, tipo: 'credencial', tipo_label: 'Credencial', nome: 'MOSTRATEC 2027', orgao: 'FUNDECT', descricao: null,
    vagas: 2, usadas: 0, disponiveis: 2, ativa: true, projetos: [],
};

const DESTAQUE = {
    id: 2, tipo: 'premio', tipo_label: 'Prêmio', nome: 'Destaque', orgao: null, descricao: null,
    vagas: null, usadas: 1, disponiveis: null, ativa: true,
    projetos: [{ id: 8, titulo: 'Abelhas nativas', categoria: 'FETEC Jr', area: 'Biológicas', observacao: null }],
};

describe('ComitePremiacao', () => {
    beforeEach(() => {
        getPremiacaoComite.mockReset().mockResolvedValue({ data: [MOSTRATEC, DESTAQUE], meta: { candidatos: CANDIDATOS } });
        premiarProjeto.mockReset().mockResolvedValue({});
        retirarPremiacao.mockReset().mockResolvedValue({});
        baixarPremiacaoComite.mockReset().mockResolvedValue();
    });

    it('mostra as credenciais, o resumo e os projetos premiados, sem o cadastro', async () => {
        render(<ComitePremiacao />);

        expect(await screen.findByText('MOSTRATEC 2027')).toBeInTheDocument();
        expect(screen.getByText('2 credencial(is) e prêmio(s) · 1 projeto(s) premiado(s)')).toBeInTheDocument();
        // O comitê só correlaciona: nada de cadastrar, desativar ou excluir.
        expect(screen.queryByRole('button', { name: /Nova credencial/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Desativar/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Excluir/ })).not.toBeInTheDocument();

        const premiados = screen.getByRole('region', { name: 'Projetos premiados' });
        expect(within(premiados).getByText('Abelhas nativas')).toBeInTheDocument();
    });

    it('premia um finalista pela credencial', async () => {
        render(<ComitePremiacao />);
        await screen.findByText('MOSTRATEC 2027');

        const cartao = screen.getByText('MOSTRATEC 2027').closest('li');
        fireEvent.focus(within(cartao).getByRole('combobox'));
        fireEvent.mouseDown(within(cartao).getByRole('button', { name: /Bioplástico/ }));
        fireEvent.click(within(cartao).getByRole('button', { name: /Premiar/ }));

        await waitFor(() => expect(premiarProjeto).toHaveBeenCalledWith(1, 7));
        expect(await screen.findByText('Bioplástico premiado com MOSTRATEC 2027.')).toBeInTheDocument();
        expect(getPremiacaoComite).toHaveBeenCalledTimes(2);
    });

    it('retira a premiação de um projeto', async () => {
        render(<ComitePremiacao />);
        await screen.findByText('MOSTRATEC 2027');

        const cartao = screen.getAllByText('Destaque')[0].closest('li');
        fireEvent.click(within(cartao).getByRole('button', { name: 'Retirar' }));

        await waitFor(() => expect(retirarPremiacao).toHaveBeenCalledWith(2, 8));
    });

    it('mostra o motivo quando o servidor recusa', async () => {
        premiarProjeto.mockRejectedValue({ fields: { projeto_id: ['Só projetos finalistas recebem credencial ou prêmio.'] } });
        render(<ComitePremiacao />);
        await screen.findByText('MOSTRATEC 2027');

        const cartao = screen.getByText('MOSTRATEC 2027').closest('li');
        fireEvent.focus(within(cartao).getByRole('combobox'));
        fireEvent.mouseDown(within(cartao).getByRole('button', { name: /Bioplástico/ }));
        fireEvent.click(within(cartao).getByRole('button', { name: /Premiar/ }));

        expect(await screen.findByText('Só projetos finalistas recebem credencial ou prêmio.')).toBeInTheDocument();
    });

    it('baixa a lista de premiação', async () => {
        render(<ComitePremiacao />);
        await screen.findByText('MOSTRATEC 2027');

        fireEvent.click(screen.getByRole('button', { name: /Lista de premiação/ }));

        await waitFor(() => expect(baixarPremiacaoComite).toHaveBeenCalled());
    });

    it('sem lista final ativa avisa que não há quem premiar', async () => {
        getPremiacaoComite.mockResolvedValue({ data: [MOSTRATEC], meta: { candidatos: [] } });
        render(<ComitePremiacao />);

        expect(await screen.findByText(/Não há lista final ativa/)).toBeInTheDocument();
    });

    it('sem credencial ativa aponta para onde se cadastra', async () => {
        getPremiacaoComite.mockResolvedValue({ data: [], meta: { candidatos: CANDIDATOS } });
        render(<ComitePremiacao />);

        expect(await screen.findByText(/Nenhuma credencial ou prêmio ativo nesta edição/)).toBeInTheDocument();
    });
});
