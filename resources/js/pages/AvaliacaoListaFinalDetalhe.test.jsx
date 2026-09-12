import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useParams: () => ({ id: '3' }),
}));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));

const DADOS = {
    lista: { id: 3, nome: 'Oficial 2026', vigente: true, versao: 1, projetos: 1 },
    itens: [{
        projeto_id: 10, codigo: 'FET.AGR-001', titulo: 'Bioplástico',
        categoria: 'FETECMS', area: 'Ciências Agrárias', escola: 'EE Alfa', manual: false,
    }],
    candidatos: [
        { id: 20, titulo: 'Robótica', categoria: 'FETEC Jr', area: 'Engenharias', media: 7.5 },
    ],
};

const getListaFinal = vi.fn(() => Promise.resolve(DADOS));
const adicionarNaListaFinal = vi.fn(() => Promise.resolve({ ...DADOS, lista: { ...DADOS.lista, versao: 2, projetos: 2 } }));
const removerDaListaFinal = vi.fn(() => Promise.resolve({ ...DADOS, itens: [], lista: { ...DADOS.lista, versao: 2, projetos: 0 } }));
vi.mock('../lib/admin.js', () => ({
    getListaFinal: (...a) => getListaFinal(...a),
    adicionarNaListaFinal: (...a) => adicionarNaListaFinal(...a),
    removerDaListaFinal: (...a) => removerDaListaFinal(...a),
    baixarListaOficial: vi.fn(),
    // A seção de identificação (Sprint 123) mora dentro desta tela e busca os
    // participantes sozinha; aqui ela responde vazia, e tem teste próprio.
    getIdentificacao: () => Promise.resolve({
        lista: { id: 1, nome: 'Lista oficial', versao: 1, demo: false },
        total: 0, por_papel: {}, participantes: [],
    }),
    baixarIdentificacao: vi.fn(),
    urlCodigo: (codigo, tipo) => `/api/v1/admin/avaliacao/identificacao/${tipo}/${codigo}.svg`,
}));

import AvaliacaoListaFinalDetalhe from './AvaliacaoListaFinalDetalhe.jsx';

describe('AvaliacaoListaFinalDetalhe', () => {
    beforeEach(() => {
        adicionarNaListaFinal.mockClear();
        removerDaListaFinal.mockClear();
    });

    it('mostra a composição atual com o código e a versão da lista', async () => {
        render(<AvaliacaoListaFinalDetalhe />);

        expect(await screen.findByText('Bioplástico')).toBeInTheDocument();
        expect(screen.getByText('FET.AGR-001')).toBeInTheDocument();
        expect(screen.getByText(/Versão 1 · 1 projeto/)).toBeInTheDocument();
        expect(screen.getByText('vigente')).toBeInTheDocument();
    });

    it('exige justificativa para retirar um projeto', async () => {
        render(<AvaliacaoListaFinalDetalhe />);
        await screen.findByText('Bioplástico');

        fireEvent.click(screen.getByRole('button', { name: /Retirar/ }));

        const confirmar = screen.getByRole('button', { name: 'Retirar' });
        expect(confirmar).toBeDisabled();

        fireEvent.change(screen.getByLabelText(/Justificativa/), { target: { value: 'Descumpriu o edital.' } });
        expect(confirmar).toBeEnabled();

        fireEvent.click(confirmar);
        await waitFor(() => expect(removerDaListaFinal).toHaveBeenCalledWith('3', 10, 'Descumpriu o edital.'));
    });

    it('inclui um projeto escolhido entre os candidatos, com justificativa', async () => {
        render(<AvaliacaoListaFinalDetalhe />);
        await screen.findByText('Bioplástico');

        // O botão só habilita depois de escolher o projeto no combobox.
        expect(screen.getByRole('button', { name: /Incluir/ })).toBeDisabled();

        fireEvent.change(screen.getByPlaceholderText(/Buscar entre os projetos avaliados/), { target: { value: 'Rob' } });
        // O combobox seleciona no mouseDown (antes de perder o foco).
        fireEvent.mouseDown(await screen.findByText('Robótica'));
        fireEvent.click(screen.getByRole('button', { name: /Incluir/ }));

        fireEvent.change(screen.getByLabelText(/Justificativa/), { target: { value: 'Recurso deferido.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Incluir' }));

        await waitFor(() => expect(adicionarNaListaFinal).toHaveBeenCalledWith('3', 20, 'Recurso deferido.'));
    });
});
