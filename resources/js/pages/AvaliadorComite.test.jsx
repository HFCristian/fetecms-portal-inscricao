import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? '', fields: e?.fields ?? {} }),
}));

const getComiteAvaliador = vi.fn();
const recomendarProjeto = vi.fn();
const retirarRecomendacao = vi.fn();
vi.mock('../lib/avaliador.js', () => ({
    getComiteAvaliador: (...a) => getComiteAvaliador(...a),
    recomendarProjeto: (...a) => recomendarProjeto(...a),
    retirarRecomendacao: (...a) => retirarRecomendacao(...a),
}));

import AvaliadorComite from './AvaliadorComite.jsx';

const MOSTRATEC = {
    id: 1, tipo: 'credencial', tipo_label: 'Credencial', nome: 'MOSTRATEC 2027', orgao: 'Fundação Liberato',
    descricao: null, informacoes: 'Só projetos do ensino médio.', vagas: 1,
};
const CANDIDATOS = [
    { id: 7, codigo: 'FET.AGR-001', titulo: 'Bioplástico de mandioca', categoria: 'FETECMS', area: 'Ciências Agrárias', escola: 'EE Alfa' },
    { id: 8, codigo: 'JR.BIO-001', titulo: 'Abelhas nativas', categoria: 'FETEC Jr', area: 'Ciências Biológicas', escola: 'EE Beta' },
];
const MINHA = {
    id: 3, credencial_id: 1, projeto_id: 8, projeto: 'Abelhas nativas', categoria: 'FETEC Jr', area: 'Ciências Biológicas',
    justificativa: null, status: 'pendente', status_label: 'Aguardando a organização', criada_em: '2026-10-13T10:00:00-04:00',
};

const painel = (recomendacoes = []) => ({ credenciais: [MOSTRATEC], candidatos: CANDIDATOS, recomendacoes });

describe('AvaliadorComite', () => {
    beforeEach(() => {
        getComiteAvaliador.mockReset().mockResolvedValue(painel([MINHA]));
        recomendarProjeto.mockReset().mockResolvedValue({ data: painel([MINHA, { ...MINHA, id: 4, projeto_id: 7, projeto: 'Bioplástico de mandioca' }]) });
        retirarRecomendacao.mockReset().mockResolvedValue({ data: painel([]) });
    });

    it('mostra a credencial com as informações adicionais e o que ele já recomendou', async () => {
        render(<AvaliadorComite />);

        expect(await screen.findByText('MOSTRATEC 2027')).toBeInTheDocument();
        expect(screen.getByText('Só projetos do ensino médio.')).toBeInTheDocument();
        expect(screen.getByText('Suas recomendações')).toBeInTheDocument();
        expect(screen.getByText('Aguardando a organização')).toBeInTheDocument();
    });

    it('recomenda um finalista com justificativa (sem repetir o já recomendado)', async () => {
        render(<AvaliadorComite />);
        await screen.findByText('MOSTRATEC 2027');

        fireEvent.focus(screen.getByRole('combobox'));
        // "Abelhas nativas" ele já recomendou: não volta na busca.
        const lista = screen.getByRole('combobox').parentElement;
        expect(within(lista).queryByRole('button', { name: /Abelhas nativas/ })).not.toBeInTheDocument();
        fireEvent.mouseDown(within(lista).getByRole('button', { name: /Bioplástico/ }));
        fireEvent.change(screen.getByLabelText(/Por que recomendar/), { target: { value: ' Estande impecável. ' } });
        fireEvent.click(screen.getByRole('button', { name: /Recomendar/ }));

        await waitFor(() => expect(recomendarProjeto).toHaveBeenCalledWith(1, 7, 'Estande impecável.'));
        expect(await screen.findByText('FET.AGR-001 — Bioplástico de mandioca recomendado para MOSTRATEC 2027.')).toBeInTheDocument();
    });

    it('retira a recomendação pendente', async () => {
        render(<AvaliadorComite />);
        await screen.findByText('Suas recomendações');

        fireEvent.click(screen.getByRole('button', { name: 'Retirar' }));

        await waitFor(() => expect(retirarRecomendacao).toHaveBeenCalledWith(3));
        await waitFor(() => expect(screen.queryByText('Suas recomendações')).not.toBeInTheDocument());
    });

    it('decidida não se retira', async () => {
        getComiteAvaliador.mockResolvedValue(painel([{ ...MINHA, status: 'aprovada', status_label: 'Aprovada' }]));
        render(<AvaliadorComite />);

        expect(await screen.findByText('Aprovada')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Retirar' })).not.toBeInTheDocument();
    });

    it('mostra o motivo quando o servidor recusa', async () => {
        recomendarProjeto.mockRejectedValue({ fields: { projeto_id: ['Você já recomendou este projeto para esta credencial.'] } });
        render(<AvaliadorComite />);
        await screen.findByText('MOSTRATEC 2027');

        fireEvent.focus(screen.getByRole('combobox'));
        fireEvent.mouseDown(screen.getByRole('button', { name: /Bioplástico/ }));
        fireEvent.click(screen.getByRole('button', { name: /Recomendar/ }));

        expect(await screen.findByText('Você já recomendou este projeto para esta credencial.')).toBeInTheDocument();
    });

    it('sem lista final avisa', async () => {
        getComiteAvaliador.mockResolvedValue({ credenciais: [MOSTRATEC], candidatos: [], recomendacoes: [] });
        render(<AvaliadorComite />);

        expect(await screen.findByText(/A lista final ainda não foi publicada/)).toBeInTheDocument();
    });

    it('leva ao painel da premiação', async () => {
        render(<AvaliadorComite />);
        await screen.findByText('MOSTRATEC 2027');

        expect(screen.getByRole('link', { name: /Painel da premiação/ })).toHaveAttribute('href', '/avaliador/comite/painel');
    });

    // Sprint 203: a busca acha o finalista pelo código da lista final.
    it('busca o finalista pelo código', async () => {
        render(<AvaliadorComite />);
        await screen.findByText('MOSTRATEC 2027');

        const combo = screen.getByRole('combobox');
        fireEvent.change(combo, { target: { value: 'FET.AGR-001' } });

        expect(within(combo.parentElement).getByRole('button', { name: /FET\.AGR-001 — Bioplástico de mandioca/ })).toBeInTheDocument();
    });
});
